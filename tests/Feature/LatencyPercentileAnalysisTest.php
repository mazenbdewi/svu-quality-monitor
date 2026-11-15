<?php

namespace Tests\Feature;

use App\Models\ControlChartBaseline;
use App\Models\InstitutionSetting;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SpcSignal;
use App\Services\LatencyPercentileAnalysis;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatencyPercentileAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->service = MonitoredService::factory()->create([
            'check_type' => 'http', 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 60, 'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
        ]);
    }

    public function test_type_seven_percentiles_and_fixed_local_utc_boundaries(): void
    {
        foreach ([100, 200, 300, 400] as $index => $milliseconds) {
            $this->check('2026-11-01 '.sprintf('%02d', $index).':00:00', $milliseconds);
        }

        $result = app(LatencyPercentileAnalysis::class)->analyze($this->service);

        $this->assertSame('2026-10-17T21:00:00+00:00', $result['period_start']);
        $this->assertSame('2026-11-14T21:00:00+00:00', $result['period_end']);
        $this->assertSame($result['period_end'], $result['data_cutoff']);
        $this->assertSame('[start,end)', $result['boundary']);
        $this->assertSame(4, $result['latency_observations']);
        $this->assertSame(250.0, $result['p50_ms']);
        $this->assertEqualsWithDelta(370, $result['p90_ms'], 1e-9);
        $this->assertEqualsWithDelta(385, $result['p95_ms'], 1e-9);
        $this->assertEqualsWithDelta(397, $result['p99_ms'], 1e-9);
        $this->assertSame(100, $result['minimum_ms']);
        $this->assertSame(400, $result['maximum_ms']);
        $this->assertSame(250.0, $result['mean_ms']);
        $this->assertSame('insufficient_data', $result['sample_status']);
        $this->assertGreaterThan(0, $result['coverage']['missing_opportunities']);
        $this->assertSame('insufficient_coverage', $result['coverage']['status']);
    }

    public function test_empty_and_single_observation_have_explicit_status(): void
    {
        $analysis = app(LatencyPercentileAnalysis::class);
        $empty = $analysis->analyze($this->service);
        $this->assertSame('no_data', $empty['sample_status']);
        $this->assertNull($empty['p99_ms']);
        $this->assertSame(0, $empty['latency_observations']);
        $this->assertSame('no_data', $empty['coverage']['status']);

        $this->check('2026-11-01 00:00:00', 123);
        $one = $analysis->analyze($this->service);
        foreach (['p50_ms', 'p90_ms', 'p95_ms', 'p99_ms'] as $key) {
            $this->assertSame(123.0, $one[$key]);
        }
        $this->assertSame('insufficient_data', $one['sample_status']);
    }

    public function test_duplicate_values_failure_missing_latency_and_manual_checks_are_separate(): void
    {
        $this->check('2026-11-01 00:00:00', 100);
        $this->check('2026-11-01 01:00:00', 100);
        $this->check('2026-11-01 02:00:00', 100);
        $this->check('2026-11-01 03:00:00', 400, success: false);
        $this->check('2026-11-01 04:00:00', null);
        $this->check('2026-11-01 05:00:00', 900, source: 'manual');
        $this->check('2026-11-01 06:00:00', 900, metadata: ['is_diagnostic' => true]);
        $this->check('2026-11-01 07:00:00', 900, metadata: ['is_synthetic' => true]);
        $this->check('2026-11-01 08:00:00', 900, maintenance: true);

        $result = app(LatencyPercentileAnalysis::class)->analyze($this->service);

        $this->assertSame(3, $result['latency_observations']);
        $this->assertSame(5, $result['eligible_checks']);
        $this->assertSame(1, $result['failed_checks']);
        $this->assertSame(1, $result['successful_without_valid_latency']);
        foreach (['p50_ms', 'p90_ms', 'p95_ms', 'p99_ms'] as $key) {
            $this->assertSame(100.0, $result[$key]);
        }
        $this->assertSame(5, $result['coverage']['covered_opportunities']);
    }

    public function test_boundaries_and_record_time_cutoff_exclude_future_and_late_changes(): void
    {
        $this->check('2026-10-17 20:59:59', 999);
        $this->check('2026-10-17 21:00:00', 100);
        $this->check('2026-11-14 20:59:59', 200);
        $this->check('2026-11-14 21:00:00', 999);
        $this->check('2026-11-10 00:00:00', 999, recorded: '2026-11-15 00:00:00');
        $changed = $this->check('2026-11-10 01:00:00', 999);
        $changed->forceFill(['updated_at' => '2026-11-15 00:00:00'])->save();

        $result = app(LatencyPercentileAnalysis::class)->analyze($this->service);

        $this->assertSame(2, $result['latency_observations']);
        $this->assertSame(150.0, $result['p50_ms']);
        $this->assertSame(100, $result['minimum_ms']);
        $this->assertSame(200, $result['maximum_ms']);
    }

    public function test_recorded_maintenance_window_excludes_untagged_checks_and_opportunities(): void
    {
        $this->check('2026-11-01 00:00:00', 100);
        $this->check('2026-11-01 01:00:00', 900);
        $this->check('2026-11-01 02:00:00', 200);
        MaintenanceWindow::query()->create([
            'name' => 'Planned maintenance',
            'starts_at' => '2026-11-01 01:00:00',
            'ends_at' => '2026-11-01 02:00:00',
            'applies_to_all_services' => true,
        ]);

        $result = app(LatencyPercentileAnalysis::class)->analyze($this->service);

        $this->assertSame(2, $result['eligible_checks']);
        $this->assertSame(2, $result['latency_observations']);
        $this->assertSame(150.0, $result['p50_ms']);
        $this->assertSame(671, $result['coverage']['expected_opportunities']);
        $this->assertSame(2, $result['coverage']['covered_opportunities']);
    }

    public function test_institution_timezone_changes_calendar_boundaries_and_services_are_independent(): void
    {
        InstitutionSetting::query()->create(['institution_name' => 'SVU', 'timezone' => 'America/New_York']);
        $other = MonitoredService::factory()->create(['check_type' => 'api', 'created_at' => '2026-10-01 00:00:00']);
        $this->check('2026-11-14 20:59:59', 110);
        $this->check('2026-11-15 04:59:59', 220);
        $this->check('2026-11-15 05:00:00', 330);
        $this->check('2026-11-01 00:00:00', 990, service: $other);
        MonitoredService::factory()->create(['check_type' => 'dns']);

        $rows = collect(app(LatencyPercentileAnalysis::class)->analyzeAll());
        $this->assertCount(2, $rows);
        $http = $rows->firstWhere('service_id', $this->service->id);
        $api = $rows->firstWhere('service_id', $other->id);
        $this->assertSame('2026-10-18T04:00:00+00:00', $http['period_start']);
        $this->assertSame('2026-11-15T05:00:00+00:00', $http['period_end']);
        $this->assertSame(2, $http['latency_observations']);
        $this->assertSame(165.0, $http['p50_ms']);
        $this->assertSame(1, $api['latency_observations']);
        $this->assertSame(990.0, $api['p95_ms']);
    }

    public function test_repeatability_and_no_research_mutation_with_configuration_warning(): void
    {
        $this->service->update(['warning_response_ms' => 3000, 'critical_response_ms' => 1500]);
        $this->check('2026-11-01 00:00:00', 111);
        $before = ServiceCheck::query()->orderBy('id')->get()->toArray();
        $analysis = app(LatencyPercentileAnalysis::class);

        $first = $analysis->analyze($this->service->fresh());
        $second = $analysis->analyze($this->service->fresh());

        $this->assertSame($first, $second);
        $this->assertSame(['critical_response_below_warning_response'], $first['configuration_warnings']);
        $this->assertSame($before, ServiceCheck::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('spc_signals', 0);
        $this->assertSame(0, ControlChartBaseline::count() + SpcSignal::count());
    }

    private function check(
        string $at,
        ?int $milliseconds,
        bool $success = true,
        string $source = ServiceCheck::SOURCE_AUTOMATIC,
        array $metadata = [],
        bool $maintenance = false,
        ?string $recorded = null,
        ?MonitoredService $service = null,
    ): ServiceCheck {
        $service ??= $this->service;
        $check = $service->serviceChecks()->make([
            'checked_at' => $at, 'source' => $source, 'check_type' => $service->check_type->value,
            'is_success' => $success, 'is_slow' => false, 'response_time_ms' => $milliseconds,
            'is_during_maintenance' => $maintenance, 'metadata' => $metadata,
        ]);
        $check->forceFill(['created_at' => $recorded ?? $at, 'updated_at' => $recorded ?? $at])->save();

        return $check;
    }
}
