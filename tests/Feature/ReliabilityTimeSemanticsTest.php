<?php

namespace Tests\Feature;

use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\User;
use App\Services\ReliabilityMetricCalculator;
use App\Services\SlaCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReliabilityTimeSemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-10 00:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function service(string $created = '2026-09-01 00:00:00'): MonitoredService
    {
        return MonitoredService::factory()->create(['created_at' => $created, 'check_interval_minutes' => 60, 'sla_enabled' => true, 'sla_target_percent' => 99.9]);
    }

    private function checks(MonitoredService $service, string $from, string $to, array $attributes = []): void
    {
        for ($at = Carbon::parse($from); $at->lte(Carbon::parse($to)); $at->addHour()) {
            $service->serviceChecks()->create(array_replace(['checked_at' => $at->copy(), 'source' => 'automatic', 'is_during_maintenance' => false, 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'response_time_ms' => 100], $attributes));
        }
    }

    private function incident(MonitoredService $service, string $from, ?string $to, bool $confirmed = true): void
    {
        $service->serviceIncidents()->create(['started_at' => $from, 'confirmed_at' => $confirmed ? $from : null, 'ended_at' => $to, 'status' => $to ? 'closed' : 'open']);
    }

    private function calculate(MonitoredService $service, string $from = '2026-09-01', string $to = '2026-09-02', ?string $cutoff = null): ReliabilityMetric
    {
        return app(ReliabilityMetricCalculator::class)->calculateForService($service, Carbon::parse($from), Carbon::parse($to), 'custom', $cutoff ? Carbon::parse($cutoff) : null);
    }

    public function test_created_time_and_first_actual_observation_bound_the_start(): void
    {
        $service = $this->service('2026-09-01 10:00:00');
        $this->checks($service, '2026-09-01 09:00', '2026-09-01 09:00'); // Invalid pre-creation record cannot expand exposure.
        $this->checks($service, '2026-09-01 12:00', '2026-09-02');
        $metric = $this->calculate($service);
        $this->assertSame('2026-09-01T12:00:00+00:00', $metric->measurement_context['observation_start']);
        $this->assertSame(43200, $metric->measurement_context['eligible_seconds']);
        $this->assertSame('100.0000', $metric->availability_percent);
    }

    public function test_future_time_and_actual_data_cutoff_are_excluded(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-01 10:00');
        $this->incident($service, '2026-09-01 09:00', null);
        $this->travelTo(Carbon::parse('2026-09-01 10:20'));
        $metric = $this->calculate($service, '2026-09-01', '2026-10-01');
        $this->assertSame(36000, $metric->measurement_context['eligible_seconds']);
        $this->assertSame(3600, $metric->measurement_context['downtime_seconds']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $metric->measurement_context['data_cutoff']);
        $this->assertNull($metric->mttr_minutes);
        $this->assertSame(1, $metric->measurement_context['open_incident_count']);
    }

    public function test_historical_snapshot_does_not_grow_with_now_and_explicit_cutoff_is_retained(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-03');
        $this->incident($service, '2026-09-01 12:00', null);
        $a = $this->calculate($service);
        $this->travelTo(Carbon::parse('2028-01-01'));
        $b = $this->calculate($service);
        $this->assertSame($a->measurement_context, $b->measurement_context);
        $this->assertSame($a->id, $b->id);
        $limited = $this->calculate($service, '2026-09-01', '2026-09-02', '2026-09-01 18:00');
        $this->assertSame(64800, $limited->measurement_context['eligible_seconds']);
        $this->assertSame(21600, $limited->measurement_context['downtime_seconds']);
    }

    public function test_completed_repair_uses_full_duration_in_completion_period_only(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-04');
        $this->incident($service, '2026-09-01 23:00', '2026-09-02 02:00');
        $first = $this->calculate($service);
        $second = $this->calculate($service, '2026-09-02', '2026-09-03');
        $this->assertSame(1, $first->incidents_count);
        $this->assertSame(60, $first->downtime_minutes);
        $this->assertNull($first->mttr_minutes);
        $this->assertSame(0, $second->incidents_count);
        $this->assertSame(120, $second->downtime_minutes);
        $this->assertSame('180.00', $second->mttr_minutes);
        $this->assertSame(1, $second->measurement_context['completed_incident_count']);
        $this->assertNull($this->calculate($service, '2026-09-03', '2026-09-04')->mttr_minutes);
    }

    public function test_three_day_incident_has_one_failure_start_and_unconfirmed_incidents_are_ignored(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-04');
        $this->incident($service, '2026-09-01 22:00', '2026-09-03 02:00');
        $this->incident($service, '2026-09-02 03:00', '2026-09-02 04:00', false);
        $counts = [];
        foreach ([1, 2, 3] as $day) {
            $counts[] = $this->calculate($service, "2026-09-0{$day}", '2026-09-0'.($day + 1))->incidents_count;
        }
        $this->assertSame([1, 0, 0], $counts);
        $this->assertSame(1680, $this->calculate($service, '2026-09-01', '2026-09-04')->downtime_minutes);
    }

    public function test_overlapping_incidents_and_maintenance_are_unions_and_match_sla(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $this->incident($service, '2026-09-01 01:00', '2026-09-01 04:00');
        $this->incident($service, '2026-09-01 03:00', '2026-09-01 05:00');
        foreach ([['02:00', '03:30'], ['03:00', '04:00']] as [$from, $to]) {
            MaintenanceWindow::create(['name' => 'Legacy overlap', 'starts_at' => '2026-09-01 '.$from, 'ends_at' => '2026-09-01 '.$to, 'applies_to_all_services' => true]);
        }
        $metric = $this->calculate($service);
        $sla = app(SlaCalculator::class)->calculate($service, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-02'));
        $this->assertSame(7200, $metric->measurement_context['downtime_seconds']);
        $this->assertSame(79200, $metric->measurement_context['eligible_seconds']);
        $this->assertSame($sla->unplanned_downtime_seconds, $metric->measurement_context['downtime_seconds']);
        $this->assertSame($sla->eligible_observation_seconds, $metric->measurement_context['eligible_seconds']);
        $this->assertEqualsWithDelta((float) $sla->availability_percent, (float) $metric->availability_percent, 0.0001);
    }

    public function test_incident_entirely_in_maintenance_does_not_count_as_unplanned_failure_or_repair(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        MaintenanceWindow::create(['name' => 'Planned', 'starts_at' => '2026-09-01 02:00', 'ends_at' => '2026-09-01 04:00', 'applies_to_all_services' => true]);
        $this->incident($service, '2026-09-01 02:10', '2026-09-01 03:00');
        $metric = $this->calculate($service);
        $this->assertSame(0, $metric->downtime_minutes);
        $this->assertSame(0, $metric->incidents_count);
        $this->assertSame('100.0000', $metric->availability_percent);
        $this->assertNull($metric->mttr_minutes);
    }

    public static function boundaries(): array
    {
        return [['2026-09-01', '2026-09-02', '2026-09-03'], ['2026-09-30', '2026-10-01', '2026-10-02'], ['2026-12-31', '2027-01-01', '2027-01-02']];
    }

    #[DataProvider('boundaries')]
    public function test_midnight_month_and_year_boundaries_do_not_double_count(string $from, string $boundary, string $to): void
    {
        $service = $this->service($from);
        $this->checks($service, $from, $to);
        $this->incident($service, $boundary, Carbon::parse($boundary)->addHour()->toDateTimeString());
        $first = $this->calculate($service, $from, $boundary);
        $second = $this->calculate($service, $boundary, $to);
        $this->assertSame(24, $first->total_checks);
        $this->assertSame(24, $second->total_checks);
        $this->assertSame(0, $first->incidents_count);
        $this->assertSame(1, $second->incidents_count);
        $this->assertSame(0, $first->downtime_minutes);
        $this->assertSame(60, $second->downtime_minutes);
    }

    public function test_no_data_and_manual_only_do_not_produce_availability(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02', ['source' => 'manual']);
        $metric = $this->calculate($service);
        $this->assertSame('no_data', $metric->measurement_context['status']);
        $this->assertNull($metric->availability_percent);
        $this->assertNull($metric->mtbf_minutes);
        $this->assertSame(0, $metric->total_checks);
    }

    public function test_insufficient_coverage_and_stale_tail_do_not_imply_healthy(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-01 02:00');
        $metric = $this->calculate($service);
        $this->assertSame('insufficient_coverage', $metric->measurement_context['status']);
        $this->assertNull($metric->availability_percent);
        $this->assertSame(7200, $metric->measurement_context['eligible_seconds']);
        $this->assertSame(21, $metric->measurement_context['coverage']['missing_checks']);
    }

    public function test_full_coverage_without_failures_has_null_mtbf_and_zero_observed_failure_rate(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $metric = $this->calculate($service);
        $this->assertSame('observed', $metric->measurement_context['status']);
        $this->assertSame('100.0000', $metric->availability_percent);
        $this->assertNull($metric->mtbf_minutes);
        $this->assertSame('0.00000000', $metric->failure_rate);
    }

    public function test_success_and_conformity_are_distinct_from_incident_availability(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $checks = $service->serviceChecks()->orderBy('checked_at')->get();
        $checks[1]->update(['is_slow' => true, 'performance_status' => 'warning']);
        $checks[2]->update(['is_slow' => true, 'performance_status' => 'critical']);
        $checks[3]->update(['is_success' => false]); // Isolated failed check, not a confirmed outage.
        $metric = $this->calculate($service);
        $this->assertSame('100.0000', $metric->availability_percent);
        $this->assertEquals(23 / 24, $metric->measurement_context['successful_check_ratio']);
        $this->assertEquals(21 / 24, $metric->measurement_context['acceptable_performance_ratio']);
        $this->assertSame(0, $metric->downtime_minutes);
        $this->incident($service, '2026-09-01 03:00', '2026-09-01 04:00');
        $this->assertSame(60, $this->calculate($service)->downtime_minutes);
    }

    public function test_mtbf_failure_rate_and_full_repair_mean_use_seconds_before_rounding(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $this->incident($service, '2026-09-01 02:00', '2026-09-01 02:30');
        $this->incident($service, '2026-09-01 14:00', '2026-09-01 14:30');
        $metric = $this->calculate($service);
        $this->assertSame('95.8333', $metric->availability_percent);
        $this->assertSame('690.00', $metric->mtbf_minutes);
        $this->assertSame('30.00', $metric->mttr_minutes);
        $this->assertSame('0.00144928', $metric->failure_rate);
    }

    public function test_unconfirmed_incident_does_not_change_any_incident_based_metric(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $this->incident($service, '2026-09-01 01:00', '2026-09-01 20:00', false);
        $metric = $this->calculate($service);
        $this->assertSame(0, $metric->incidents_count);
        $this->assertSame(0, $metric->downtime_minutes);
        $this->assertSame('100.0000', $metric->availability_percent);
        $this->assertNull($metric->mttr_minutes);
    }

    public function test_repair_ending_at_boundary_is_assigned_to_following_period(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-03');
        $this->incident($service, '2026-09-01 23:00', '2026-09-02');
        $first = $this->calculate($service);
        $second = $this->calculate($service, '2026-09-02', '2026-09-03');
        $this->assertNull($first->mttr_minutes);
        $this->assertSame('60.00', $second->mttr_minutes);
        $this->assertSame(0, $second->incidents_count);
        $this->assertSame(0, $second->downtime_minutes);
    }

    public function test_creation_time_wins_over_invalid_earlier_observations(): void
    {
        $service = $this->service('2026-09-01 12:00:00');
        $this->checks($service, '2026-09-01', '2026-09-02');
        $metric = $this->calculate($service);
        $this->assertSame(43200, $metric->measurement_context['eligible_seconds']);
        $this->assertSame(12, $metric->total_checks);
    }

    public function test_subminute_durations_are_not_rounded_before_availability(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $this->incident($service, '2026-09-01 00:00:10', '2026-09-01 00:00:20');
        $this->incident($service, '2026-09-01 00:01:10', '2026-09-01 00:01:20');
        $metric = $this->calculate($service);
        $this->assertSame(20, $metric->measurement_context['downtime_seconds']);
        $this->assertSame('99.9769', $metric->availability_percent);
        $this->assertSame('0.17', $metric->mttr_minutes);
    }

    public function test_all_maintenance_is_no_data_not_zero_availability(): void
    {
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        MaintenanceWindow::create(['name' => 'All period', 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-02', 'applies_to_all_services' => true]);
        $metric = $this->calculate($service);
        $this->assertNull($metric->availability_percent);
        $this->assertSame('no_data', $metric->measurement_context['status']);
        $this->assertSame(0, $metric->measurement_context['eligible_seconds']);
    }

    public function test_no_data_page_explains_missing_data_instead_of_rendering_a_percentage(): void
    {
        config(['app.env' => 'local']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');
        $metric = $this->calculate($this->service());
        $this->actingAs($user)->get(ReliabilityMetricResource::getUrl('view', ['record' => $metric]))
            ->assertOk()->assertSee(__('monitoring.reliability_insufficient_data'));
    }

    public function test_default_daily_command_calculates_yesterday_with_exclusive_next_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-09-02 00:10'));
        $service = $this->service();
        $this->checks($service, '2026-09-01', '2026-09-02');
        $this->artisan('reliability:calculate --period=daily')->assertSuccessful();
        $metric = $service->reliabilityMetrics()->sole();
        $this->assertSame('2026-09-01 00:00:00', $metric->period_start->toDateTimeString());
        $this->assertSame('2026-09-02 00:00:00', $metric->period_end->toDateTimeString());
        $this->assertSame(24, $metric->total_checks);
    }

    public function test_weighted_summary_excludes_unknown_and_refuses_overlapping_snapshots(): void
    {
        $a = $this->service();
        $b = $this->service();
        $this->checks($a, '2026-09-01', '2026-09-02');
        $this->checks($b, '2026-09-01 12:00', '2026-09-02');
        $this->incident($b, '2026-09-01 12:00', '2026-09-02');
        $this->calculate($a);
        $this->calculate($b);
        $this->calculate($this->service());
        $this->assertEqualsWithDelta(100 * 24 / 36, ReliabilityMetric::weightedAvailability(ReliabilityMetric::query()), 0.00001);
        $this->calculate($a, '2026-09-01 01:00', '2026-09-02');
        $this->assertNull(ReliabilityMetric::weightedAvailability(ReliabilityMetric::query()));
    }
}
