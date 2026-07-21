<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckDueMonitoredServicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_active_services_with_no_previous_checks(): void
    {
        Http::fake([
            'https://due.test' => Http::response('OK', 200),
        ]);

        $service = MonitoredService::factory()->create([
            'url' => 'https://due.test',
            'is_active' => true,
            'expected_keyword' => null,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 1')
            ->expectsOutput('Due services count: 1')
            ->expectsOutput('Checked services count: 1')
            ->expectsOutput('Successful checks count: 1')
            ->expectsOutput('Failed checks count: 0')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('service_checks', [
            'monitored_service_id' => $service->id,
            'status_code' => 200,
            'is_success' => true,
        ]);
    }

    public function test_it_skips_inactive_services(): void
    {
        Http::fake();

        MonitoredService::factory()->create([
            'url' => 'https://inactive.test',
            'is_active' => false,
        ]);

        $this->artisan('services:check-due')
            ->expectsOutput('Active services count: 0')
            ->expectsOutput('Due services count: 0')
            ->expectsOutput('Checked services count: 0')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_skips_services_checked_recently(): void
    {
        Http::fake();

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
            ->expectsOutput('Checked services count: 0')
            ->expectsOutput('Skipped services count: 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, $service->serviceChecks()->count());
    }

    public function test_it_checks_services_whose_interval_has_passed(): void
    {
        Http::fake([
            'https://expired.test' => Http::response('OK', 200),
        ]);

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
            ->expectsOutput('Checked services count: 1')
            ->expectsOutput('Successful checks count: 1')
            ->expectsOutput('Skipped services count: 0')
            ->assertSuccessful();

        $this->assertSame(2, $service->serviceChecks()->count());
    }
}
