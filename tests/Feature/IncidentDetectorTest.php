<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\IncidentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IncidentDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_does_not_open_incident_after_one_failed_check(): void
    {
        $service = MonitoredService::factory()->create();

        $incident = $this->createAndEvaluate($service, now(), isSuccess: false, isSlow: false, errorType: 'timeout');

        $this->assertNull($incident);
        $this->assertDatabaseCount('service_incidents', 0);
    }

    public function test_it_confirms_incident_after_two_consecutive_functional_failures(): void
    {
        Carbon::setTestNow('2026-07-08 10:30:00');

        $service = MonitoredService::factory()->create();

        $this->createAndEvaluate($service, now()->subMinutes(30), isSuccess: false, isSlow: false, errorType: 'timeout');
        $incident = $this->createAndEvaluate($service, now()->subMinutes(15), isSuccess: false, isSlow: false, errorType: 'timeout');

        $this->assertNotNull($incident);
        $this->assertSame('open', $incident->status);
        $this->assertSame('timeout', $incident->incident_type);
        $this->assertSame('critical', $incident->severity);
        $this->assertTrue($incident->started_at->equalTo(now()->subMinutes(30)));
        $this->assertTrue($incident->confirmed_at->equalTo(now()->subMinutes(15)));
    }

    public function test_it_does_not_open_duplicate_incident_when_one_is_already_open(): void
    {
        $service = MonitoredService::factory()->create();

        $this->createAndEvaluate($service, now()->subMinutes(30), isSuccess: false, isSlow: false, errorType: 'timeout');
        $check = $this->createCheck($service, now()->subMinutes(15), isSuccess: false, isSlow: false, errorType: 'timeout');
        app(IncidentDetector::class)->evaluate($service, $check);
        app(IncidentDetector::class)->evaluate($service, $check);

        $this->assertSame(1, $service->serviceIncidents()->count());
    }

    public function test_it_closes_incident_after_two_consecutive_healthy_checks(): void
    {
        Carbon::setTestNow('2026-07-08 11:15:00');

        $service = MonitoredService::factory()->create();
        $incident = $service->serviceIncidents()->create([
            'started_at' => now()->subMinutes(75),
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'open',
        ]);

        $this->createAndEvaluate($service, now()->subMinutes(15), isSuccess: true, isSlow: false);
        $updatedIncident = $this->createAndEvaluate($service, now(), isSuccess: true, isSlow: false);

        $this->assertSame($incident->id, $updatedIncident?->id);
        $this->assertSame('closed', $updatedIncident?->status);
        $this->assertTrue($updatedIncident?->ended_at->equalTo(now()->subMinutes(15)));
        $this->assertSame(60, $updatedIncident?->duration_minutes);
    }

    public function test_it_does_not_close_incident_after_only_one_healthy_check(): void
    {
        $service = MonitoredService::factory()->create();
        $incident = $service->serviceIncidents()->create([
            'started_at' => now()->subHour(),
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'open',
        ]);

        $updatedIncident = $this->createAndEvaluate($service, now(), isSuccess: true, isSlow: false);

        $this->assertSame($incident->id, $updatedIncident?->id);
        $this->assertSame('open', $updatedIncident?->status);
        $this->assertNull($updatedIncident?->ended_at);
    }

    private function createCheck(
        MonitoredService $service,
        Carbon $checkedAt,
        bool $isSuccess,
        bool $isSlow,
        ?string $errorType = null,
    ): ServiceCheck {
        return $service->serviceChecks()->create([
            'checked_at' => $checkedAt,
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $isSlow ? 2000 : 100,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
            'error_type' => $errorType,
        ]);
    }

    private function createAndEvaluate(MonitoredService $service, Carbon $checkedAt, bool $isSuccess, bool $isSlow, ?string $errorType = null): ?ServiceIncident
    {
        $check = $this->createCheck($service, $checkedAt, $isSuccess, $isSlow, $errorType);

        return app(IncidentDetector::class)->evaluate($service, $check);
    }
}
