<?php

namespace Tests\Feature;

use App\Models\ControlChartBaseline;
use App\Models\InstitutionSetting;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SpcSignal;
use App\Services\SliIndicatorAnalysis;
use App\Services\SloCalibrationAnalysis;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SloCalibrationAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->service = MonitoredService::factory()->create([
            'check_type' => 'http', 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440, 'warning_response_ms' => 2500, 'critical_response_ms' => 500,
        ]);
    }

    public function test_distinct_sli_denominators_and_exclusions(): void
    {
        $this->check('2026-11-01 00:00:00', 100);
        $this->check('2026-11-01 01:00:00', 500);
        $this->check('2026-11-01 02:00:00', 200, success: false);
        $this->check('2026-11-01 03:00:00', null);
        $this->check('2026-11-01 04:00:00', 100, source: ServiceCheck::SOURCE_MANUAL);
        $this->check('2026-11-01 05:00:00', 100, metadata: ['is_diagnostic' => true]);
        $this->check('2026-11-01 06:00:00', 100, metadata: ['is_synthetic' => true]);
        $this->check('2026-11-01 07:00:00', 100, maintenance: true);
        $this->check('2026-11-01 08:00:00', 100);
        MaintenanceWindow::query()->create([
            'name' => 'Maintenance', 'starts_at' => '2026-11-01 08:00:00',
            'ends_at' => '2026-11-01 09:00:00', 'applies_to_all_services' => true,
        ]);

        $result = app(SliIndicatorAnalysis::class)->describe(
            $this->service, Carbon::parse('2026-10-17 21:00:00', 'UTC'),
            Carbon::parse('2026-11-14 21:00:00', 'UTC'), 200
        );

        $this->assertSame(4, $result['executed_checks']);
        $this->assertSame(3, $result['successful_checks']);
        $this->assertSame(1, $result['failed_checks']);
        $this->assertSame(1, $result['successful_without_valid_latency']);
        $this->assertSame(2, $result['successful_with_valid_latency']);
        $this->assertSame(1, $result['successful_within_objective']);
        $this->assertSame(75.0, $result['availability_percent']);
        $this->assertSame(50.0, $result['conditional_successful_latency_percent']);
        $this->assertSame(25.0, $result['combined_latency_success_percent']);
        $this->assertLessThanOrEqual($result['availability_percent'], $result['combined_latency_success_percent']);
    }

    public function test_zero_success_and_empty_denominators_are_explicit(): void
    {
        $analysis = app(SliIndicatorAnalysis::class);
        $start = Carbon::parse('2026-10-17 21:00:00', 'UTC');
        $end = Carbon::parse('2026-11-14 21:00:00', 'UTC');
        $empty = $analysis->describe($this->service, $start, $end, 100);
        $this->assertSame(0, $empty['executed_checks']);
        $this->assertNull($empty['availability_percent']);
        $this->assertNull($empty['conditional_successful_latency_percent']);
        $this->assertNull($empty['combined_latency_success_percent']);

        $this->check('2026-11-01 00:00:00', null, success: false);
        $failed = $analysis->describe($this->service, $start, $end, 100);
        $this->assertSame(1, $failed['executed_checks']);
        $this->assertSame(0, $failed['successful_checks']);
        $this->assertSame(0.0, $failed['availability_percent']);
        $this->assertNull($failed['conditional_successful_latency_percent']);
        $this->assertSame(0.0, $failed['combined_latency_success_percent']);
        $this->assertSame('insufficient_evidence', app(SloCalibrationAnalysis::class)->propose($this->service)['status']);
    }

    public function test_provisional_p90_p99_rounding_and_scientific_traceability(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $this->check(Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day)->toDateTimeString(), ($day + 1) * 100);
        }
        $this->check('2026-11-01 00:10:00', null, success: false);
        $analysis = app(SloCalibrationAnalysis::class);
        $first = $analysis->propose($this->service);
        $second = $analysis->propose($this->service);

        $this->assertSame($first, $second);
        $this->assertSame(SloCalibrationAnalysis::POLICY_VERSION, $first['policy_version']);
        $this->assertSame('provisional_research_only', $first['status']);
        $this->assertSame('2026-10-17T21:00:00+00:00', $first['calibration_start']);
        $this->assertSame('2026-11-14T21:00:00+00:00', $first['calibration_end']);
        $this->assertSame($first['calibration_end'], $first['calibration_cutoff']);
        $this->assertEqualsWithDelta(2530, $first['measured_p90_ms'], 1e-9);
        $this->assertEqualsWithDelta(2773, $first['measured_p99_ms'], 1e-9);
        $this->assertSame(2600, $first['proposed_p90_guardrail_ms']);
        $this->assertSame(2800, $first['proposed_combined_latency_objective_ms']);
        $this->assertSame(99, $first['proposed_combined_compliance_percent']);
        $this->assertNull($first['availability_target_percent']);
        $this->assertSame(28, $first['latency_observations']);
        $this->assertSame(29, $first['executed_checks']);
        $this->assertSame(28, $first['successful_within_proposed_objective']);
        $this->assertSame(28, $first['successful_with_valid_latency']);
        $this->assertEqualsWithDelta(2800 / 29, $first['availability_sli_percent'], 1e-9);
        $this->assertSame(100.0, $first['calibration_conditional_successful_latency_percent']);
        $this->assertEqualsWithDelta(2800 / 29, $first['calibration_combined_sli_percent'], 1e-9);
        $this->assertLessThanOrEqual($first['availability_sli_percent'], $first['calibration_combined_sli_percent']);
        $this->assertTrue($first['calibration_availability_below_proposed_combined_target']);
        $this->assertTrue($first['calibration_combined_below_proposed_target']);
        $this->assertContains('combined_target_unattainable_given_calibration_availability', $first['warnings']);
        $this->assertContains('in_sample_combined_below_proposed_target', $first['warnings']);
        $this->assertFalse($first['calibration_combined_sli_is_independent_evidence']);
        $this->assertSame(28, $first['coverage']['covered_opportunities']);
        $this->assertSame(0, $first['coverage']['missing_opportunities']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['configuration_fingerprint']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['proposal_fingerprint']);
        $this->assertContains('critical_response_below_warning_response', $first['warnings']);
        $this->assertContains('p99_tail_uncertainty_not_quantified', $first['warnings']);
    }

    public function test_inadequate_coverage_and_missing_latency_do_not_become_healthy_observations(): void
    {
        $this->check('2026-11-01 00:00:00', 123);
        $this->check('2026-11-01 01:00:00', null);
        $result = app(SloCalibrationAnalysis::class)->propose($this->service);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertContains('insufficient_coverage', $result['warnings']);
        $this->assertContains('insufficient_latency_observations', $result['warnings']);
        $this->assertSame(2, $result['executed_checks']);
        $this->assertSame(2, $result['successful_checks']);
        $this->assertSame(1, $result['successful_without_valid_latency']);
        $this->assertSame(1, $result['successful_with_valid_latency']);
        $this->assertSame(1, $result['successful_within_proposed_objective']);
        $this->assertSame(100.0, $result['availability_sli_percent']);
        $this->assertSame(100.0, $result['calibration_conditional_successful_latency_percent']);
        $this->assertSame(50.0, $result['calibration_combined_sli_percent']);
        $this->assertFalse($result['calibration_availability_below_proposed_combined_target']);
        $this->assertTrue($result['calibration_combined_below_proposed_target']);
        $this->assertGreaterThan(0, $result['coverage']['missing_opportunities']);
    }

    public function test_complete_availability_does_not_hide_missing_latency_in_combined_sli(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $this->check(Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day)->toDateTimeString(),
                $day === 27 ? null : 100);
        }

        $result = app(SloCalibrationAnalysis::class)->propose($this->service);

        $this->assertSame('provisional_research_only', $result['status']);
        $this->assertSame(100.0, $result['availability_sli_percent']);
        $this->assertSame(100.0, $result['calibration_conditional_successful_latency_percent']);
        $this->assertEqualsWithDelta(100 * 27 / 28, $result['calibration_combined_sli_percent'], 1e-9);
        $this->assertFalse($result['calibration_availability_below_proposed_combined_target']);
        $this->assertTrue($result['calibration_combined_below_proposed_target']);
        $this->assertSame(100, $result['proposed_combined_latency_objective_ms']);
        $this->assertSame(99, $result['proposed_combined_compliance_percent']);
        $this->assertContains('in_sample_combined_below_proposed_target', $result['warnings']);
    }

    public function test_twenty_observations_are_only_exploratory_for_p99_tail(): void
    {
        $this->service->update(['check_interval_minutes' => 2016]);
        for ($index = 0; $index < 20; $index++) {
            $this->check(Carbon::parse('2026-10-17 21:00:00', 'UTC')->addMinutes(2016 * $index)
                ->toDateTimeString(), ($index + 1) * 100);
        }

        $result = app(SloCalibrationAnalysis::class)->propose($this->service->fresh());

        $this->assertSame(20, $result['latency_observations']);
        $this->assertSame(20, $result['coverage']['expected_opportunities']);
        $this->assertSame('provisional_research_only', $result['status']);
        $this->assertEqualsWithDelta(1981, $result['measured_p99_ms'], 1e-9);
        $this->assertSame(2000, $result['proposed_combined_latency_objective_ms']);
        $this->assertContains('p99_tail_uncertainty_not_quantified', $result['warnings']);
        $this->assertFalse($result['calibration_combined_sli_is_independent_evidence']);
    }

    public function test_local_boundaries_no_lookahead_configuration_change_and_no_research_mutation(): void
    {
        InstitutionSetting::query()->create(['institution_name' => 'SVU', 'timezone' => 'America/New_York']);
        $this->check('2026-10-18 03:59:59', 900);
        $this->check('2026-10-18 04:00:00', 100);
        $this->check('2026-11-15 04:59:59', 200);
        $this->check('2026-11-15 05:00:00', 900);
        $this->check('2026-11-01 00:00:00', 900, recorded: '2026-11-16 00:00:00');
        $before = ServiceCheck::query()->orderBy('id')->get()->toArray();
        $analysis = app(SloCalibrationAnalysis::class);
        $first = $analysis->propose($this->service);

        $this->assertSame('2026-10-18T04:00:00+00:00', $first['calibration_start']);
        $this->assertSame('2026-11-15T05:00:00+00:00', $first['calibration_end']);
        $this->assertSame(2, $first['executed_checks']);
        $this->assertSame(190.0, $first['measured_p90_ms']);
        $this->assertSame($before, ServiceCheck::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('spc_signals', 0);
        $this->assertSame(0, ControlChartBaseline::count() + SpcSignal::count());

        $this->service->update(['check_interval_minutes' => 30]);
        $changed = $analysis->propose($this->service->fresh());
        $this->assertNotSame($first['configuration_fingerprint'], $changed['configuration_fingerprint']);
        $this->assertNotSame($first['proposal_fingerprint'], $changed['proposal_fingerprint']);
    }

    public function test_sli_window_rejects_future_evaluation_and_excludes_late_record_edits(): void
    {
        $start = Carbon::parse('2026-10-17 21:00:00', 'UTC');
        $end = Carbon::parse('2026-11-14 21:00:00', 'UTC');
        $this->check('2026-11-01 00:00:00', 100);
        $this->check('2026-11-01 01:00:00', 900, recorded: '2026-11-15 00:00:00');
        $changed = $this->check('2026-11-01 02:00:00', 900);
        $changed->forceFill(['updated_at' => '2026-11-15 00:00:00'])->save();
        $analysis = app(SliIndicatorAnalysis::class);

        $result = $analysis->describe($this->service, $start, $end, 100);
        $this->assertSame(1, $result['executed_checks']);
        $this->assertSame(1, $result['successful_within_objective']);

        $this->expectException(InvalidArgumentException::class);
        $analysis->describe($this->service, $start, Carbon::parse('2026-11-16 00:00:00', 'UTC'), 100);
    }

    private function check(
        string $at, ?int $milliseconds, bool $success = true,
        string $source = ServiceCheck::SOURCE_AUTOMATIC, array $metadata = [],
        bool $maintenance = false, ?string $recorded = null,
    ): ServiceCheck {
        $check = $this->service->serviceChecks()->make([
            'checked_at' => $at, 'source' => $source, 'check_type' => 'http',
            'is_success' => $success, 'is_slow' => false, 'response_time_ms' => $milliseconds,
            'is_during_maintenance' => $maintenance, 'metadata' => $metadata,
        ]);
        $check->forceFill(['created_at' => $recorded ?? $at, 'updated_at' => $recorded ?? $at])->save();

        return $check;
    }
}
