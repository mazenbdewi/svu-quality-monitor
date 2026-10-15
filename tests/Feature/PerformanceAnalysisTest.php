<?php

namespace Tests\Feature;

use App\Filament\Pages\PerformanceAnalysis;
use App\Models\ControlChart;
use App\Models\ControlChartBaseline;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcSignal;
use App\Models\User;
use App\Services\ControlChartCalculator;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PerformanceAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('viewer');
        $this->service = MonitoredService::factory()->create(['name' => 'Historical HTTP', 'check_type' => 'http', 'check_interval_minutes' => 5, 'created_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(string $at, array $values = []): ServiceCheck
    {
        return $this->service->serviceChecks()->create(array_merge([
            'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true,
            'is_slow' => false, 'response_time_ms' => 100, 'is_during_maintenance' => false,
        ], $values));
    }

    public function test_page_requires_control_chart_view_permission(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator)->get(PerformanceAnalysis::getUrl())->assertForbidden();
    }

    public function test_chart_assets_are_available_before_a_service_is_selected_without_a_cdn(): void
    {
        $this->actingAs($this->viewer)
            ->get(PerformanceAnalysis::getUrl())
            ->assertOk()
            ->assertSee('window.historicalSpcChart', false)
            ->assertSee('/build/assets/charts-', false)
            ->assertDontSee('cdn.jsdelivr.net', false);
    }

    public function test_service_and_period_use_wrapping_filament_selects_in_both_locales(): void
    {
        $arabicName = 'خدمة المراقبة الإلكترونية للمكتبة الجامعية والبوابة الطلابية';
        $englishName = 'University Student Portal and Electronic Library Monitoring Service';
        $this->service->update(['name' => $arabicName]);
        MonitoredService::factory()->create(['name' => $englishName, 'check_type' => 'api']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $response = $this->actingAs($this->viewer)->get(PerformanceAnalysis::getUrl());
            $response->assertOk()->assertSee('fi-select-input', false);

            $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class);
            $fields = $component->instance()->form->getComponents();
            $this->assertCount(2, $fields);
            $this->assertSame('serviceId', $fields[0]->getStatePath());
            $this->assertSame('window', $fields[1]->getStatePath());
            foreach ($fields as $field) {
                $this->assertFalse($field->isNative());
                $this->assertTrue($field->canOptionLabelsWrap());
            }
            $this->assertTrue($fields[0]->isSearchable());
            $this->assertSame($arabicName.' · HTTP', $fields[0]->getOptions()[$this->service->id]);
            $this->assertContains($englishName.' · API', $fields[0]->getOptions());
            $this->assertSame('30', $component->get('window'));
            $this->assertArrayHasKey('custom', $fields[1]->getOptions());
        }
    }

    public function test_page_reads_historical_summary_without_writing_any_analysis_rows(): void
    {
        $this->check('2026-10-13 20:00:00');
        $this->check('2026-10-13 20:05:00', ['is_slow' => true]);
        $before = [ControlChart::count(), ControlChartPoint::count(), ControlChartBaseline::count(), SpcMonitoringEvaluation::count(), SpcSignal::count(), ServiceIncident::count(), ReliabilityMetric::count(), SlaMetric::count(), ServiceCheck::count()];
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->set('window', '7')
            ->set('window', '15');
        $summary = $component->viewData('summary');
        $this->assertSame('insufficient', $summary['state']);
        $this->assertSame(2, $summary['eligible_count']);
        $this->assertSame(2, $summary['latency_count']);
        $this->assertSame(1, $summary['problematic_count']);
        $this->assertEquals(50.0, $summary['problematic_percent']);
        $this->assertSame($before, [ControlChart::count(), ControlChartPoint::count(), ControlChartBaseline::count(), SpcMonitoringEvaluation::count(), SpcSignal::count(), ServiceIncident::count(), ReliabilityMetric::count(), SlaMetric::count(), ServiceCheck::count()]);
        $this->assertSame([], $writes);
    }

    public function test_custom_range_uses_damascus_boundaries_and_excludes_end(): void
    {
        $this->check('2026-10-03 20:59:59');
        $this->check('2026-10-03 21:00:00');
        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->set('window', 'custom')
            ->set('customStart', '2026-10-04T00:00')
            ->set('customEnd', '2026-10-04T00:05');
        $summary = $component->viewData('summary');
        $this->assertSame(1, $summary['eligible_count']);
        $this->assertSame('2026-10-03 21:00:00', $summary['start']->toDateTimeString());
        $this->assertSame('2026-10-03 21:05:00', $summary['end']->toDateTimeString());
    }

    public function test_research_exclusions_and_unsupported_service_are_visible(): void
    {
        foreach ([
            ['source' => 'manual'], ['is_during_maintenance' => true],
            ['metadata' => ['is_synthetic' => true]], ['metadata' => ['is_diagnostic' => true]],
            ['check_type' => 'dns'],
        ] as $values) {
            $this->check('2026-10-13 21:00:00', $values);
        }
        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)->set('serviceId', $this->service->id);
        $summary = $component->viewData('summary');
        $this->assertSame('no_data', $summary['state']);
        $dns = MonitoredService::factory()->create(['check_type' => 'dns']);
        $component->set('serviceId', $dns->id);
        $this->assertSame('unsupported', $component->viewData('summary')['state']);
    }

    public function test_read_only_charts_reuse_r3_calculations_and_keep_missing_p_buckets_as_gaps(): void
    {
        $this->check('2026-10-13 21:00:00', ['response_time_ms' => 100]);
        $this->check('2026-10-13 21:05:00', ['response_time_ms' => 120, 'is_slow' => true]);
        $this->check('2026-10-13 23:00:00', ['response_time_ms' => 110]);
        $before = [ControlChart::count(), ControlChartBaseline::count(), SpcMonitoringEvaluation::count(), SpcSignal::count()];

        $charts = app(ControlChartCalculator::class)->analyzeReadOnly(
            $this->service,
            Carbon::parse('2026-10-13 21:00:00'),
            Carbon::parse('2026-10-14 00:00:00'),
        );

        $this->assertSame(['i_chart', 'mr_chart', 'p_chart'], array_keys($charts));
        $this->assertSame([100.0, 120.0, 110.0], array_column($charts['i_chart']['points'], 'value'));
        $this->assertSame([20.0, 10.0], array_column($charts['mr_chart']['points'], 'value'));
        $this->assertCount(3, $charts['p_chart']['display_points']);
        $this->assertNull($charts['p_chart']['display_points'][1]['value']);
        $this->assertSame($before, [ControlChart::count(), ControlChartBaseline::count(), SpcMonitoringEvaluation::count(), SpcSignal::count()]);
    }

    public function test_page_exposes_all_three_charts_without_persisting_them(): void
    {
        $this->check('2026-10-13 21:00:00');
        $this->check('2026-10-13 21:05:00', ['is_slow' => true]);

        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->set('window', '7');

        $charts = $component->get('charts');
        $this->assertSame(['i_chart', 'mr_chart', 'p_chart'], array_keys($charts));
        $this->assertSame('Asia/Damascus', $charts['i_chart']['analysis_timezone']);
        $this->assertSame('raw', $charts['mr_chart']['aggregation_interval']);
    }

    public function test_large_chart_payload_is_downsampled_for_display_only(): void
    {
        config(['monitoring.spc.visualization_max_points' => 100]);
        for ($i = 0; $i < 130; $i++) {
            $this->check(Carbon::parse('2026-10-13 20:00:00')->addSeconds($i)->toDateTimeString(), [
                'response_time_ms' => $i === 65 ? 10000 : 100 + $i,
            ]);
        }

        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->set('window', '7');
        $chart = $component->get('charts.i_chart');
        $displayPoints = json_decode($component->get('chartPayload'), true, 512, JSON_THROW_ON_ERROR)['i_chart'];

        $this->assertSame(130, $chart['sample_size']);
        $this->assertTrue($chart['visualization']['downsampled']);
        $this->assertLessThanOrEqual(100, $chart['visualization']['displayed_points']);
        $this->assertArrayNotHasKey('points', $chart);
        $this->assertContains(10000.0, array_column($displayPoints, 'value'));
        $this->assertSame(100.0, $displayPoints[0]['value']);
        $this->assertSame(229.0, $displayPoints[array_key_last($displayPoints)]['value']);
    }

    public function test_page_renders_exploratory_chart_sections(): void
    {
        $this->check('2026-10-13 21:00:00');
        $this->check('2026-10-13 21:05:00');

        $this->actingAs($this->viewer)
            ->get(PerformanceAnalysis::getUrl(['service' => $this->service->id]))
            ->assertOk()
            ->assertSee('Response Time')
            ->assertSee('Problematic Checks')
            ->assertSee('canvas')
            ->assertSee('Exploratory analysis');
    }

    public function test_read_only_p_chart_keeps_subgroup_specific_limits(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->check(sprintf('2026-10-13 21:%02d:00', $i * 5), ['is_slow' => $i < 2]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->check(sprintf('2026-10-13 22:%02d:00', $i * 5), ['is_slow' => $i === 0]);
        }

        $chart = app(ControlChartCalculator::class)->analyzeReadOnly(
            $this->service,
            Carbon::parse('2026-10-13 21:00:00'),
            Carbon::parse('2026-10-13 23:00:00'),
        )['p_chart'];
        $ucl = collect($chart['points'])->pluck('ucl')->filter()->values();

        $this->assertGreaterThanOrEqual(2, $ucl->unique()->count());
    }

    public function test_read_only_limits_and_points_match_persisted_r3_calculations_for_the_same_population(): void
    {
        $start = Carbon::parse('2026-10-13 21:00:00', 'UTC');
        $end = Carbon::parse('2026-10-14 00:00:00', 'UTC');
        $this->check('2026-10-13 20:59:59', ['response_time_ms' => 9000]);
        $this->check('2026-10-13 21:00:00', ['response_time_ms' => 100]);
        $this->check('2026-10-13 21:05:00', ['response_time_ms' => 110, 'is_slow' => true]);
        $this->check('2026-10-13 21:10:00', ['response_time_ms' => 900, 'is_success' => false]);
        $this->check('2026-10-13 23:00:00', ['response_time_ms' => 130]);
        $this->check('2026-10-13 23:05:00', ['response_time_ms' => 160]);
        $this->check('2026-10-14 00:00:00', ['response_time_ms' => 9000]);

        $calculator = app(ControlChartCalculator::class);
        $readOnly = $calculator->analyzeReadOnly($this->service, $start, $end);
        $this->assertSame(5, $readOnly['p_chart']['summary']['eligible_count']);
        $this->assertSame(4, $readOnly['i_chart']['sample_size']);

        foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
            $persisted = $calculator->calculate($this->service, $type, $start, $end, 'custom', 'hourly');
            foreach (['center_line', 'ucl', 'lcl'] as $field) {
                $this->assertEquals($persisted->{$field}, $readOnly[$type][$field]);
            }

            $savedPoints = $persisted->points()->orderBy('point_time')->orderBy('id')->get();
            $this->assertCount($savedPoints->count(), $readOnly[$type]['points']);
            foreach ($savedPoints as $index => $point) {
                $calculated = $readOnly[$type]['points'][$index];
                $this->assertSame($point->point_time->toDateTimeString(), $calculated['point_time']->toDateTimeString());
                foreach (['value', 'ucl', 'lcl'] as $field) {
                    $this->assertEquals($point->{$field}, $calculated[$field]);
                }
                $this->assertSame($point->sample_size, $calculated['sample_size']);
                $this->assertSame($point->is_out_of_control, $calculated['is_out_of_control']);
            }
        }
    }
}
