<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CheckDueMonitoredServicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_active_services_with_no_previous_checks(): void
    {
        Bus::fake();

        $service = MonitoredService::factory()->create([
            'url' => 'https://due.test',
            'is_active' => true,
            'expected_keyword' => null,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 1')
            ->expectsOutput('Due services count: 1')
            ->expectsOutput('Dispatched services count: 1')
            ->expectsOutput('Failed dispatches count: 0')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        Bus::assertDispatched(CheckMonitoredServiceJob::class, fn (CheckMonitoredServiceJob $job): bool => $job->monitoredServiceId === $service->id);
        $this->assertDatabaseCount('service_checks', 0);
    }

    public function test_it_skips_inactive_services(): void
    {
        Bus::fake();

        MonitoredService::factory()->create([
            'url' => 'https://inactive.test',
            'is_active' => false,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 0')
            ->expectsOutput('Due services count: 0')
            ->expectsOutput('Dispatched services count: 0')
            ->expectsOutput('Failed dispatches count: 0')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_it_skips_services_checked_recently(): void
    {
        Bus::fake();

        $service = MonitoredService::factory()->create([
            'url' => 'https://recent.test',
            'is_active' => true,
            'check_interval_minutes' => 15,
        ]);

        $service->serviceChecks()->create([
            'checked_at' => now()->subMinutes(10),
            'status_code' => 200,
            'response_time_ms' => 100,
            'is_success' => true,
            'is_slow' => false,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 1')
            ->expectsOutput('Due services count: 0')
            ->expectsOutput('Dispatched services count: 0')
            ->expectsOutput('Failed dispatches count: 0')
            ->expectsOutput('Skipped services count: 1')
            ->assertSuccessful();

        Bus::assertNothingDispatched();
        $this->assertSame(1, $service->serviceChecks()->count());
    }

    public function test_it_checks_services_whose_interval_has_passed(): void
    {
        Bus::fake();

        $service = MonitoredService::factory()->create([
            'url' => 'https://expired.test',
            'is_active' => true,
            'expected_keyword' => null,
            'check_interval_minutes' => 15,
        ]);

        $service->serviceChecks()->create([
            'checked_at' => now()->subMinutes(15),
            'status_code' => 200,
            'response_time_ms' => 100,
            'is_success' => true,
            'is_slow' => false,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 1')
            ->expectsOutput('Due services count: 1')
            ->expectsOutput('Dispatched services count: 1')
            ->expectsOutput('Failed dispatches count: 0')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        Bus::assertDispatched(CheckMonitoredServiceJob::class, fn (CheckMonitoredServiceJob $job): bool => $job->monitoredServiceId === $service->id);
        $this->assertSame(1, $service->serviceChecks()->count());
    }
}
