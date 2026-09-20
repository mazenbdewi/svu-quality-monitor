<?php

namespace Tests\Feature;

use App\Exports\Reports\Sheets\MinitabAllChartPointsSheet;
use App\Exports\Reports\Sheets\MinitabBucketedChecksSheet;
use App\Exports\Reports\Sheets\MinitabChartBucketsSheet;
use App\Exports\Reports\Sheets\MinitabRawChecksSheet;
use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\ControlChartCalculator;
use App\Services\ResearchInterpretationService;
use App\Services\SpcAnalysisWindow;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpcResearchFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 00:25', 'Asia/Damascus'));
        config(['monitoring.spc.analysis_timezone' => 'Asia/Damascus']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): MonitoredService
    {
        return MonitoredService::factory()->create(['created_at' => '2026-01-01', 'check_interval_minutes' => 5]);
    }

    private function check(MonitoredService $service, string $time = '2026-09-16 09:00:00', array $attributes = []): ServiceCheck
    {
        return $service->serviceChecks()->create(array_merge([
            'checked_at' => $time, 'source' => 'automatic', 'check_type' => 'http',
            'is_success' => true, 'is_slow' => false, 'response_time_ms' => 100,
            'performance_status' => 'healthy', 'is_during_maintenance' => false,
        ], $attributes));
    }

    private function chart(MonitoredService $service, string $type = 'p_chart', string $bucket = 'hourly'): ControlChart
    {
        return app(ControlChartCalculator::class)->calculate($service, $type, Carbon::parse('2026-09-16 08:00'), Carbon::parse('2026-09-16 12:00'), 'custom', $bucket);
    }

    public function test_scheduler_uses_previous_complete_local_day(): void
    {
        $service = $this->service();
        $this->check($service, '2026-09-15 21:00');
        $this->check($service, '2026-09-16 21:00');
        $this->artisan('control-charts:calculate --chart=i_chart')->assertSuccessful();
        $chart = ControlChart::firstOrFail();
        $this->assertSame('2026-09-15 21:00:00', $chart->period_start->toDateTimeString());
        $this->assertSame('2026-09-16 21:00:00', $chart->period_end->toDateTimeString());
        $this->assertSame(1, $chart->points_count);
        $this->assertFalse($chart->research_context['partial']);
        $this->assertTrue($chart->data_cutoff->eq($chart->period_end));
    }

    public static function windows(): array
    {
        return [['7', '2026-09-09 21:00:00'], ['30', '2026-08-17 21:00:00'], ['90', '2026-06-18 21:00:00']];
    }

    #[DataProvider('windows')]
    public function test_calendar_windows_are_independent_of_calculation_frequency(string $days, string $expected): void
    {
        [$start, $end] = app(SpcAnalysisWindow::class)->resolve($days);
        $this->assertSame($expected, $start->toDateTimeString());
        $this->assertSame('2026-09-16 21:00:00', $end->toDateTimeString());
        $chart = app(ControlChartCalculator::class)->analyze($this->service(), 'i_chart', $days);
        $this->assertTrue($chart->period_start->eq($start));
    }

    public function test_custom_local_boundaries_and_partial_cutoff(): void
    {
        [$start, $end] = app(SpcAnalysisWindow::class)->resolve('custom', '2026-09-17', '2026-09-18');
        $service = $this->service();
        $this->check($service, '2026-09-16 21:05');
        $this->check($service, '2026-09-16 21:30');
        $chart = app(ControlChartCalculator::class)->calculate($service, 'i_chart', $start, $end);
        $this->assertSame(1, $chart->points_count);
        $this->assertTrue($chart->research_context['partial']);
        $this->assertSame('2026-09-16 21:25:00', $chart->data_cutoff->toDateTimeString());
        $this->assertSame('Asia/Damascus', $chart->analysis_timezone);
    }

    public static function excluded(): array
    {
        return [
            [['source' => 'manual']], [['is_during_maintenance' => true]],
            [['metadata' => ['is_synthetic' => true]]], [['metadata' => ['is_diagnostic' => true]]],
            [['check_type' => 'dns']], [['check_type' => 'ssl']], [['check_type' => 'tcp']],
        ];
    }

    #[DataProvider('excluded')]
    public function test_r1_exclusions_apply_to_all_research_charts(array $attributes): void
    {
        $service = $this->service();
        $this->check($service, attributes: $attributes);
        foreach (ControlChartCalculator::RESEARCH_TYPES as $type) {
            $chart = $this->chart($service, $type);
            $this->assertSame(0, $chart->points_count);
            $this->assertSame('no_data', $chart->research_context['sufficiency']);
        }
    }

    public static function outcomes(): array
    {
        return [
            [true, false, 'healthy', null, 1, 0],
            [true, true, 'warning', null, 1, 1],
            [true, true, 'critical', null, 1, 1],
            [false, false, null, 'server_error', 0, 1],
            [false, false, null, 'timeout', 0, 1],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_latency_and_problematic_populations(bool $success, bool $slow, ?string $performance, ?string $error, int $latency, int $problematic): void
    {
        $service = $this->service();
        $this->check($service, attributes: ['is_success' => $success, 'is_slow' => $slow, 'performance_status' => $performance, 'error_type' => $error]);
        $this->assertSame($latency, $this->chart($service, 'i_chart')->points_count);
        $point = $this->chart($service)->points()->firstOrFail();
        $this->assertSame($problematic, $point->research_context['problematic_count']);
        $this->assertSame((float) $problematic, (float) $point->value);
    }

    public function test_order_is_deterministic_and_mr_does_not_reach_before_window(): void
    {
        $service = $this->service();
        $this->check($service, '2026-09-16 07:59', ['response_time_ms' => 999]);
        $a = $this->check($service, attributes: ['response_time_ms' => 100]);
        $b = $this->check($service, attributes: ['response_time_ms' => 120]);
        $c = $this->check($service, attributes: ['response_time_ms' => 110]);
        $this->check($service, '2026-09-16 12:00', ['response_time_ms' => 999]);
        $chart = $this->chart($service, 'mr_chart');
        $this->assertSame(['20.0000', '10.0000'], $chart->points()->orderBy('id')->pluck('value')->all());
        $this->assertSame($a->id, $chart->points()->first()->research_context['previous_check_id']);
        $this->assertSame($b->id, $chart->points()->first()->research_context['check_id']);
        $this->assertSame(3, $this->chart($service, 'i_chart')->points_count);
    }

    public function test_missing_partial_and_observed_buckets_and_clamped_variable_limits(): void
    {
        $service = $this->service();
        $this->check($service, '2026-09-16 09:00', ['is_success' => false]);
        for ($i = 0; $i < 12; $i++) {
            $this->check($service, Carbon::parse('2026-09-16 10:00')->addMinutes($i * 5)->toDateTimeString(), ['is_success' => $i >= 5]);
        }
        $chart = $this->chart($service);
        $buckets = $chart->research_context['buckets'];
        $this->assertSame(['missing', 'partially_observed', 'observed', 'missing'], array_column($buckets, 'status'));
        $this->assertNull($buckets[0]['problematic_proportion']);
        $this->assertSame(12, $buckets[0]['missing_count']);
        $this->assertSame(11, $buckets[1]['missing_count']);
        $this->assertSame(2, $chart->points_count);
        $this->assertEqualsWithDelta(6 / 13, (float) $chart->center_line, 0.0001);
        $points = $chart->points()->orderBy('point_time')->get();
        $this->assertSame('1.0000', $points[0]->ucl);
        $this->assertSame('0.0000', $points[0]->lcl);
        $this->assertNotSame($points[0]->ucl, $points[1]->ucl);
        $this->assertNull($chart->ucl);
        foreach ($points as $point) {
            $sigma = sqrt((6 / 13) * (7 / 13) / $point->sample_size);
            $this->assertEqualsWithDelta(min(1, 6 / 13 + 3 * $sigma), (float) $point->ucl, 0.0001);
            $this->assertEqualsWithDelta(max(0, 6 / 13 - 3 * $sigma), (float) $point->lcl, 0.0001);
        }
    }

    public function test_daily_and_hourly_and_different_windows_coexist(): void
    {
        $service = $this->service();
        $this->check($service);
        $hourly = $this->chart($service);
        $daily = $this->chart($service, bucket: 'daily');
        $this->assertNotSame($hourly->id, $daily->id);
        $this->assertSame('daily', $daily->aggregation_interval);
        $short = app(ControlChartCalculator::class)->analyze($service, 'p_chart', '7');
        $long = app(ControlChartCalculator::class)->analyze($service, 'p_chart');
        $this->assertNotSame($short->id, $long->id);
        $this->assertSame(4, ControlChart::count());
    }

    public function test_recalculation_only_replaces_matching_identity_and_rolls_back_point_failure(): void
    {
        $service = $this->service();
        $this->check($service);
        $chart = $this->chart($service);
        $other = $this->chart($service, bucket: 'daily');
        $otherIds = $other->points()->pluck('id')->all();
        $values = $chart->points()->pluck('value')->all();
        $again = $this->chart($service);
        $this->assertSame($chart->id, $again->id);
        $this->assertSame($values, $again->points()->pluck('value')->all());
        $ids = $again->points()->pluck('id')->all();
        $this->check($service, '2026-09-16 10:00');
        $dispatcher = clone ControlChartPoint::getEventDispatcher();
        ControlChartPoint::creating(function () {
            throw new \RuntimeException('Injected point failure');
        });
        try {
            $this->chart($service);
            $this->fail('Expected point failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected point failure', $e->getMessage());
        } finally {
            ControlChartPoint::setEventDispatcher($dispatcher);
        }
        $this->assertSame($ids, $chart->points()->pluck('id')->all());
        $this->assertSame(1, $chart->fresh()->points_count);
        $this->assertSame($otherIds, $other->points()->pluck('id')->all());
    }

    public function test_legacy_chart_is_not_overwritten_or_reclassified(): void
    {
        $service = $this->service();
        $legacy = ControlChart::create(['monitored_service_id' => $service->id, 'chart_type' => 'i_chart', 'metric_name' => 'response_time_ms', 'period_type' => 'custom', 'period_start' => '2026-09-16 08:00', 'period_end' => '2026-09-16 12:00', 'center_line' => 42]);
        $chart = $this->chart($service, 'i_chart');
        $this->assertNotSame($legacy->id, $chart->id);
        $this->assertNull($legacy->fresh()->analysis_mode);
        $this->assertSame('42.0000', $legacy->fresh()->center_line);
        $this->assertSame('legacy', app(ResearchInterpretationService::class)->controlChartFinding($legacy)['level']);
    }

    public function test_no_data_and_one_point_are_not_stable(): void
    {
        $service = $this->service();
        $this->assertSame('no_data', $this->chart($service, 'i_chart')->research_context['sufficiency']);
        $this->check($service);
        foreach (ControlChartCalculator::RESEARCH_TYPES as $type) {
            $chart = $this->chart($service, $type);
            $this->assertSame('insufficient', $chart->research_context['sufficiency']);
            $this->assertSame('insufficient', app(ResearchInterpretationService::class)->controlChartFinding($chart)['level']);
        }
    }

    public function test_export_parity_for_raw_latency_buckets_and_all_points(): void
    {
        $service = $this->service();
        $this->check($service);
        $this->check($service, '2026-09-16 09:05', ['is_slow' => true, 'performance_status' => 'critical']);
        $this->check($service, '2026-09-16 10:00', ['is_success' => false]);
        $this->check($service, '2026-09-16 10:05', ['source' => 'manual']);
        $filters = ['service_id' => $service->id, 'analysis_start' => '2026-09-16T08:00:00Z', 'analysis_end' => '2026-09-16T12:00:00Z'];
        $raw = new MinitabRawChecksSheet($filters);
        $checks = $raw->query()->get();
        $i = $this->chart($service, 'i_chart');
        $this->assertSame($i->points()->orderBy('id')->get()->pluck('research_context.check_id')->all(), $checks->filter(fn ($c) => $raw->map($c)[19] === 1)->pluck('id')->all());
        $p = $this->chart($service);
        $rows = (new MinitabBucketedChecksSheet($filters))->collection();
        $this->assertSame(3, $checks->count());
        foreach ($p->research_context['buckets'] as $idx => $bucket) {
            $this->assertSame($bucket['problematic_count'], $rows[$idx][7]);
            $this->assertSame($bucket['problematic_proportion'], $rows[$idx][8]);
            $this->assertSame($bucket['observed_count'], $rows[$idx][4]);
        }
        $export = new MinitabAllChartPointsSheet($filters + ['chart_id' => $p->id]);
        $points = $export->query()->get();
        $this->assertCount($p->points_count, $points);
        foreach ($points as $point) {
            $row = $export->map($point);
            $this->assertSame((float) $point->ucl, $row[6]);
            $this->assertSame((float) $point->lcl, $row[7]);
            $this->assertSame($p->id, $row[11]);
            $this->assertSame('exploratory', $row[16]);
            $this->assertSame('spc-r3-v1', $row[17]);
        }
    }

    public function test_maintenance_windows_exclude_unflagged_samples_and_expected_slots(): void
    {
        $service = $this->service();
        MaintenanceWindow::create(['name' => 'Planned', 'starts_at' => '2026-09-16 09:00', 'ends_at' => '2026-09-16 10:00', 'applies_to_all_services' => true]);
        $this->check($service);
        $this->check($service, '2026-09-16 10:00');
        $chart = $this->chart($service);
        $this->assertSame(1, $chart->points_count);
        $bucket = $chart->research_context['buckets'][1];
        $this->assertSame('not_expected', $bucket['status']);
        $this->assertSame(0, $bucket['expected_count']);
        $this->assertSame(1, $this->chart($service, 'i_chart')->points_count);
    }

    public function test_invalid_latency_is_excluded_without_losing_binary_outcomes(): void
    {
        $service = $this->service();
        $this->check($service, attributes: ['response_time_ms' => null]);
        $this->assertSame(0, $this->chart($service, 'i_chart')->points_count);
        $this->assertSame(1, $this->chart($service)->points_count);
    }

    public function test_sufficiency_policy_is_configurable_and_never_certifies_stability(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 48; $i++) {
            $this->check($service, Carbon::parse('2026-09-16 08:00')->addMinutes($i * 5)->toDateTimeString());
        }
        $this->assertSame('preliminary', $this->chart($service)->research_context['sufficiency']);
        config(['monitoring.spc.exploratory_min_points' => 4]);
        $chart = $this->chart($service);
        $this->assertSame('analyzable', $chart->research_context['sufficiency']);
        $this->assertEquals(100, $chart->research_context['coverage']);
        $this->assertSame('analyzable', app(ResearchInterpretationService::class)->controlChartFinding($chart)['level']);
        $this->assertSame('gray', app(ResearchInterpretationService::class)->controlChartFinding($chart)['color']);
    }

    public function test_repeated_checks_do_not_fill_missing_sampling_slots(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 12; $i++) {
            $this->check($service);
        }
        $bucket = $this->chart($service)->research_context['buckets'][1];
        $this->assertSame(12, $bucket['observed_count']);
        $this->assertSame(11, $bucket['missing_count']);
        $this->assertSame('partially_observed', $bucket['status']);
    }

    public function test_timezone_is_part_of_identity_and_does_not_change_utc_inputs(): void
    {
        $service = $this->service();
        $damascus = $this->chart($service);
        config(['monitoring.spc.analysis_timezone' => 'UTC']);
        $utc = $this->chart($service);
        $this->assertNotSame($damascus->id, $utc->id);
        $this->assertTrue($damascus->period_start->eq($utc->period_start));
        $this->assertSame('Asia/Damascus', $damascus->fresh()->analysis_timezone);
    }

    public function test_daily_subgroups_use_local_midnight_and_actual_denominators(): void
    {
        $service = $this->service();
        $this->check($service, '2026-09-15 21:00', ['is_success' => false]);
        $this->check($service, '2026-09-16 20:59');
        $this->check($service, '2026-09-16 21:00');
        $chart = app(ControlChartCalculator::class)->analyze($service, 'p_chart', 'custom', '2026-09-16', '2026-09-18', 'daily');
        $points = $chart->points()->orderBy('point_time')->get();
        $this->assertSame([2, 1], $points->pluck('sample_size')->all());
        $this->assertSame(['0.5000', '0.0000'], $points->pluck('value')->all());
        $this->assertSame('2026-09-15 21:00:00', $points[0]->point_time->toDateTimeString());
    }

    public function test_persisted_missing_buckets_are_exportable_without_recomputing(): void
    {
        $service = $this->service();
        $chart = $this->chart($service);
        $this->check($service); // Added after snapshot, must not replace stored gap in this export.
        $export = new MinitabChartBucketsSheet(['chart_id' => $chart->id]);
        $rows = $export->collection();
        $this->assertCount(4, $rows);
        $this->assertSame(0, $rows[1][7]);
        $this->assertNull($rows[1][11]);
        $this->assertSame('missing', $rows[1][12]);
    }

    public function test_research_page_shows_context_and_null_gap_graph(): void
    {
        $service = $this->service();
        $this->check($service);
        $chart = $this->chart($service);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');
        $this->actingAs($user)->get(ControlChartResource::getUrl('view', ['record' => $chart]))
            ->assertOk()->assertSee('Asia/Damascus')->assertSee(__('monitoring.spc_research.insufficient'))
            ->assertSee(__('monitoring.spc_research.limits'));
        $html = view('filament.infolists.control-chart-graph', ['record' => $chart])->render();
        $this->assertStringContainsString('null', $html);
        $this->assertStringContainsString('spanGaps', $html);
    }

    public function test_p_aggregation_option_does_not_change_raw_chart_identity_or_context(): void
    {
        $service = $this->service();
        $this->check($service);
        $first = $this->chart($service, 'i_chart');
        $second = $this->chart($service, 'i_chart', 'daily');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('raw', $second->aggregation_interval);
        $this->assertSame($first->research_context, $second->research_context);
    }
}
