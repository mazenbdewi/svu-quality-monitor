<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitoredServiceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_with_no_checks_returns_unknown_status(): void
    {
        $service = MonitoredService::factory()->create();

        $this->assertSame('unknown', $service->current_status);
        $this->assertSame('Unknown', $service->current_status_label);
        $this->assertSame('gray', $service->current_status_color);
    }

    public function test_latest_failed_check_returns_down_status(): void
    {
        $service = MonitoredService::factory()->create();

        $this->createCheck($service, now()->subMinute(), isSuccess: true, isSlow: false);
        $this->createCheck($service, now(), isSuccess: false, isSlow: false, errorType: 'timeout');

        $service->load('latestServiceCheck');

        $this->assertSame('down', $service->current_status);
        $this->assertSame('Timeout', $service->last_problem_type_label);
    }

    public function test_latest_successful_slow_check_returns_slow_status(): void
    {
        $service = MonitoredService::factory()->create();

        $this->createCheck($service, now(), isSuccess: true, isSlow: true);

        $service->load('latestServiceCheck');

        $this->assertSame('slow', $service->current_status);
        $this->assertSame('Slow', $service->last_problem_type_label);
    }

    public function test_latest_successful_non_slow_check_returns_healthy_status(): void
    {
        $service = MonitoredService::factory()->create();

        $this->createCheck($service, now(), isSuccess: true, isSlow: false);

        $service->load('latestServiceCheck');

        $this->assertSame('healthy', $service->current_status);
        $this->assertSame('-', $service->last_problem_type_label);
    }

    public function test_service_with_open_incident_reports_has_open_incident(): void
    {
        $service = MonitoredService::factory()->create();

        $service->serviceIncidents()->create([
            'started_at' => now(),
            'incident_type' => 'down',
            'severity' => 'high',
            'status' => 'open',
        ]);

        $service->load('openIncident');

        $this->assertTrue($service->has_open_incident);
    }

    public function test_never_checked_query_matches_services_without_checks(): void
    {
        $neverChecked = MonitoredService::factory()->create();
        $checked = MonitoredService::factory()->create();

        $this->createCheck($checked, now(), isSuccess: true, isSlow: false);

        $services = MonitoredService::query()->whereDoesntHave('serviceChecks')->pluck('id');

        $this->assertTrue($services->contains($neverChecked->id));
        $this->assertFalse($services->contains($checked->id));
    }

    public function test_open_incident_query_matches_services_with_open_incidents(): void
    {
        $withOpenIncident = MonitoredService::factory()->create();
        $withoutOpenIncident = MonitoredService::factory()->create();

        $withOpenIncident->serviceIncidents()->create([
            'started_at' => now(),
            'incident_type' => 'down',
            'severity' => 'high',
            'status' => 'open',
        ]);

        $withoutOpenIncident->serviceIncidents()->create([
            'started_at' => now(),
            'ended_at' => now()->addMinutes(10),
            'duration_minutes' => 10,
            'incident_type' => 'down',
            'severity' => 'high',
            'status' => 'closed',
        ]);

        $services = MonitoredService::query()->whereHas('openIncident')->pluck('id');

        $this->assertTrue($services->contains($withOpenIncident->id));
        $this->assertFalse($services->contains($withoutOpenIncident->id));
    }

    public function test_latest_check_queries_match_current_status_filters(): void
    {
        $healthy = MonitoredService::factory()->create();
        $slow = MonitoredService::factory()->create();
        $down = MonitoredService::factory()->create();

        $this->createCheck($healthy, now()->subMinutes(10), isSuccess: false, isSlow: false);
        $this->createCheck($healthy, now(), isSuccess: true, isSlow: false);
        $this->createCheck($slow, now(), isSuccess: true, isSlow: true);
        $this->createCheck($down, now(), isSuccess: false, isSlow: false);

        $healthyServices = MonitoredService::query()
            ->whereHas('latestServiceCheck', fn ($query) => $query
                ->where('is_success', true)
                ->where('is_slow', false))
            ->pluck('id');

        $slowServices = MonitoredService::query()
            ->whereHas('latestServiceCheck', fn ($query) => $query
                ->where('is_success', true)
                ->where('is_slow', true))
            ->pluck('id');

        $downServices = MonitoredService::query()
            ->whereHas('latestServiceCheck', fn ($query) => $query->where('is_success', false))
            ->pluck('id');

        $this->assertTrue($healthyServices->contains($healthy->id));
        $this->assertFalse($healthyServices->contains($down->id));
        $this->assertTrue($slowServices->contains($slow->id));
        $this->assertTrue($downServices->contains($down->id));
    }

    private function createCheck(
        MonitoredService $service,
        Carbon $checkedAt,
        bool $isSuccess,
        bool $isSlow,
        ?string $errorType = null,
    ): void {
        $service->serviceChecks()->create([
            'checked_at' => $checkedAt,
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $isSlow ? 2000 : 100,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
            'error_type' => $errorType,
        ]);
    }
}
