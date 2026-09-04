<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Services\IncidentDetector;
use App\Services\ReliabilityMetricCalculator;
use App\Services\ServiceCheckRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_failure_then_success_clears_pending_state_without_an_incident(): void
    {
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        $this->check($service, true, source: 'manual');
        $service->refresh();
        $this->assertSame('healthy', $service->operational_state);
        $this->assertSame(0, $service->consecutive_failures);
        $this->assertDatabaseCount('service_incidents', 0);
    }

    public function test_recovery_regression_keeps_the_same_incident_open_until_confirmed_again(): void
    {
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        $this->check($service, false);
        $incident = $service->openIncident()->firstOrFail();
        $this->check($service, true);
        $this->check($service, false);
        $service->refresh();
        $this->assertSame('down', $service->operational_state);
        $this->assertSame('open', $incident->fresh()->status);
        $this->check($service, true);
        $this->check($service, true);
        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertDatabaseCount('service_incidents', 1);
    }

    public function test_confirmed_timestamps_and_recovery_duration_use_first_failure_and_first_recovery_success(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        Carbon::setTestNow('2026-08-31 10:01:00');
        $this->check($service, false);
        $incident = $service->openIncident()->firstOrFail();
        Carbon::setTestNow('2026-08-31 10:10:00');
        $this->check($service, true);
        Carbon::setTestNow('2026-08-31 10:11:00');
        $this->check($service, true);
        $incident = $incident->fresh();
        $this->assertSame('2026-08-31 10:00:00', $incident->started_at->toDateTimeString());
        $this->assertSame('2026-08-31 10:01:00', $incident->confirmed_at->toDateTimeString());
        $this->assertSame('2026-08-31 10:10:00', $incident->resolved_at->toDateTimeString());
        $this->assertSame(10, $incident->duration_minutes);
        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, now()->startOfDay(), now()->endOfDay());
        $this->assertSame(10, $metric->downtime_minutes);
    }

    public function test_reprocessing_the_same_check_is_idempotent(): void
    {
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        $check = $service->serviceChecks()->latest('id')->first();
        app(IncidentDetector::class)->evaluate($service, $check);
        $this->assertSame(1, $service->fresh()->consecutive_failures);
        $this->assertDatabaseCount('service_incidents', 0);
    }

    public function test_repeated_state_transitions_flag_flapping_without_creating_an_incident(): void
    {
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        $this->check($service, true);
        $this->check($service, false);
        $this->check($service, true);

        $this->assertTrue($service->fresh()->is_flapping);
        $this->assertDatabaseCount('service_incidents', 0);
    }

    private function check(MonitoredService $service, bool $success, string $source = 'automatic')
    {
        return app(ServiceCheckRunner::class)->persist($service, new CheckResult($success, now(), statusCode: $success ? 200 : 500, responseTimeMs: 10, errorType: $success ? null : 'timeout', errorMessage: $success ? null : 'The request timed out.'), $source);
    }
}
