<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Services\MaintenanceWindowService;
use App\Services\ReliabilityMetricCalculator;
use App\Services\ServiceCheckRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceWindowsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_future_maintenance_does_not_affect_current_checks(): void
    {
        Carbon::setTestNow('2026-08-31 09:00:00');
        $service = MonitoredService::factory()->create();
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');

        $check = $this->check($service, false);

        $this->assertFalse($check->is_during_maintenance);
        $this->assertNull($check->maintenance_window_id);
        $this->assertSame('pending_failure', $service->fresh()->operational_state);
    }

    public function test_active_maintenance_marks_checks_and_suppresses_new_incident_confirmation(): void
    {
        Carbon::setTestNow('2026-08-31 10:20:00');
        $service = MonitoredService::factory()->create();
        $window = $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');

        $first = $this->check($service, false, 'manual');
        Carbon::setTestNow('2026-08-31 10:21:00');
        $second = $this->check($service, false);
        $success = $this->check($service, true);

        $this->assertTrue($first->is_during_maintenance);
        $this->assertSame($window->id, $first->maintenance_window_id);
        $this->assertSame('manual', $first->source);
        $this->assertTrue($second->is_during_maintenance);
        $this->assertTrue($success->is_success);
        $this->assertDatabaseCount('service_incidents', 0);
        $this->assertSame('healthy', $service->fresh()->operational_state);
    }

    public function test_failures_after_maintenance_start_a_fresh_confirmation_sequence(): void
    {
        Carbon::setTestNow('2026-08-31 10:30:00');
        $service = MonitoredService::factory()->create();
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');
        $this->check($service, false);
        $this->check($service, false);

        Carbon::setTestNow('2026-08-31 11:01:00');
        $this->check($service, false);
        $this->assertSame('pending_failure', $service->fresh()->operational_state);
        Carbon::setTestNow('2026-08-31 11:02:00');
        $this->check($service, false);

        $incident = $service->openIncident()->firstOrFail();
        $this->assertSame('2026-08-31 11:01:00', $incident->started_at->toDateTimeString());
        $this->assertSame('2026-08-31 11:02:00', $incident->confirmed_at->toDateTimeString());
    }

    public function test_existing_incident_remains_open_and_can_recover_during_maintenance(): void
    {
        Carbon::setTestNow('2026-08-31 09:58:00');
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        Carbon::setTestNow('2026-08-31 09:59:00');
        $this->check($service, false);
        $incident = $service->openIncident()->firstOrFail();
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');

        Carbon::setTestNow('2026-08-31 10:10:00');
        $this->check($service, true);
        Carbon::setTestNow('2026-08-31 10:11:00');
        $this->check($service, true);

        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertSame('2026-08-31 10:10:00', $incident->fresh()->resolved_at->toDateTimeString());
    }

    public function test_reliability_excludes_maintenance_from_unplanned_downtime_and_observation_time(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => '2026-08-31 08:00:00', 'check_interval_minutes' => 60]);
        Carbon::setTestNow('2026-08-31 14:00:00');
        for ($hour = 8; $hour <= 13; $hour++) {
            $service->serviceChecks()->create(['checked_at' => sprintf('2026-08-31 %02d:00:00', $hour), 'source' => 'automatic', 'is_success' => true, 'is_slow' => false]);
        }
        $service->serviceIncidents()->create([
            'started_at' => Carbon::parse('2026-08-31 09:00:00'), 'ended_at' => Carbon::parse('2026-08-31 12:00:00'),
            'confirmed_at' => '2026-08-31 09:00:00', 'status' => 'closed', 'incident_type' => 'down', 'severity' => 'critical',
        ]);
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, Carbon::parse('2026-08-31 08:00:00'), Carbon::parse('2026-08-31 13:00:00'));

        $this->assertSame(120, $metric->downtime_minutes);
        $this->assertSame(60, $metric->planned_maintenance_minutes);
        $this->assertSame(240, $metric->observation_minutes);
        $this->assertSame('50.0000', $metric->availability_percent);
    }

    public function test_overlap_calculation_merges_legacy_overlapping_windows_without_double_subtraction(): void
    {
        $service = MonitoredService::factory()->create();
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');
        $overlap = MaintenanceWindow::query()->create(['name' => 'Legacy overlap', 'starts_at' => '2026-08-31 10:30:00', 'ends_at' => '2026-08-31 11:30:00']);
        $overlap->monitoredServices()->attach($service);

        $minutes = app(MaintenanceWindowService::class)->overlapMinutes($service, Carbon::parse('2026-08-31 09:00:00'), Carbon::parse('2026-08-31 12:00:00'));

        $this->assertSame(90, $minutes);
    }

    public function test_validation_rejects_same_service_overlap_but_allows_another_service(): void
    {
        $first = MonitoredService::factory()->create();
        $second = MonitoredService::factory()->create();
        $this->window($first, '2026-08-31 10:00:00', '2026-08-31 11:00:00');
        $service = app(MaintenanceWindowService::class);

        try {
            $service->assertValidWindow(Carbon::parse('2026-08-31 10:30:00'), Carbon::parse('2026-08-31 12:00:00'), false, [$first->id]);
            $this->fail('Expected overlap validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }

        $service->assertValidWindow(Carbon::parse('2026-08-31 10:30:00'), Carbon::parse('2026-08-31 12:00:00'), false, [$second->id]);
        $this->assertTrue(true);
    }

    public function test_all_services_window_conflicts_with_a_service_specific_window(): void
    {
        $service = MonitoredService::factory()->create();
        $this->window($service, '2026-08-31 10:00:00', '2026-08-31 11:00:00');

        $this->expectException(ValidationException::class);
        app(MaintenanceWindowService::class)->assertValidWindow(
            Carbon::parse('2026-08-31 10:30:00'), Carbon::parse('2026-08-31 12:00:00'), true, [],
        );
    }

    private function window(MonitoredService $service, string $startsAt, string $endsAt): MaintenanceWindow
    {
        $window = MaintenanceWindow::query()->create(['name' => 'Planned work', 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
        $window->monitoredServices()->attach($service);

        return $window;
    }

    private function check(MonitoredService $service, bool $success, string $source = 'automatic')
    {
        return app(ServiceCheckRunner::class)->persist($service, new CheckResult($success, now(), statusCode: $success ? 200 : 500, responseTimeMs: 10, errorType: $success ? null : 'timeout', errorMessage: $success ? null : 'Timed out.'), $source);
    }
}
