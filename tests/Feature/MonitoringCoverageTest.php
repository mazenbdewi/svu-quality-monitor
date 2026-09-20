<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Services\MonitoringCoverageCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function check(MonitoredService $service, string $at, array $attributes = []): void
    {
        $service->serviceChecks()->create(array_replace(['checked_at' => Carbon::parse('2026-09-01 '.$at), 'source' => 'automatic', 'check_type' => 'http', 'is_success' => false, 'is_slow' => false, 'is_during_maintenance' => false], $attributes));
    }

    private function coverage(MonitoredService $service, string $start = '10:00', string $end = '10:30'): array
    {
        return app(MonitoringCoverageCalculator::class)->calculate($service, Carbon::parse('2026-09-01 '.$start), Carbon::parse('2026-09-01 '.$end));
    }

    public function test_missing_intervals_reduce_coverage_and_bursts_do_not_hide_gaps(): void
    {
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 10]);
        foreach (['10:00', '10:01', '10:02', '10:20'] as $at) {
            $this->check($service, $at);
        }
        $this->check($service, '10:10', ['source' => 'manual']);
        $this->check($service, '10:11', ['metadata' => ['is_synthetic' => true]]);
        $result = $this->coverage($service);
        $this->assertSame(3, $result['expected_automatic_checks']);
        $this->assertSame(4, $result['observed_automatic_checks']);
        $this->assertSame(1, $result['missing_checks']);
        $this->assertSame(66.67, $result['coverage_percent']);
        $this->assertSame('insufficient_coverage', $result['status']);
    }

    public function test_no_automatic_history_is_unknown_not_healthy(): void
    {
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 10]);
        $this->check($service, '10:00', ['source' => 'manual']);
        $result = $this->coverage($service);
        $this->assertSame('no_data', $result['status']);
        $this->assertNull($result['coverage_percent']);
        $this->assertNull($result['effective_start']);
        $this->assertArrayNotHasKey('availability_percent', $result);
    }

    public function test_half_open_boundaries_first_observation_and_partial_interval(): void
    {
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 10]);
        foreach (['10:10', '10:20', '10:30'] as $at) {
            $this->check($service, $at);
        }
        $result = $this->coverage($service);
        $this->assertSame(2, $result['expected_automatic_checks']);
        $this->assertSame(2, $result['observed_automatic_checks']);
        $this->assertSame(100.0, $result['coverage_percent']);
        $this->assertSame('observed', $result['status']); // Failures still establish monitoring coverage.
        $this->assertSame(3, $this->coverage($service, '10:00', '10:31')['expected_automatic_checks']);
        $later = $this->coverage($service, '11:00', '11:30');
        $this->assertSame(3, $later['missing_checks']);
        $this->assertSame('no_data', $later['status']);
    }

    public function test_maintenance_is_removed_from_exposure_and_observed_checks(): void
    {
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 10]);
        foreach (['10:00', '10:10', '10:20'] as $at) {
            $this->check($service, $at);
        }
        MaintenanceWindow::query()->create(['name' => 'Planned', 'starts_at' => '2026-09-01 10:10', 'ends_at' => '2026-09-01 10:20', 'applies_to_all_services' => true]);
        $result = $this->coverage($service);
        $this->assertEquals(600, $result['planned_maintenance_seconds']);
        $this->assertSame(2, $result['expected_automatic_checks']);
        $this->assertSame(2, $result['observed_automatic_checks']);
        $this->assertSame('observed', $result['status']);
        $this->assertSame('no_data', $this->coverage($service, '10:10', '10:20')['status']);
    }

    public function test_future_time_is_not_in_expected_exposure(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 10:15'));
        $service = MonitoredService::factory()->create(['check_interval_minutes' => 10]);
        $this->check($service, '10:00');
        $this->check($service, '10:10');
        $this->check($service, '10:20');
        $result = $this->coverage($service);
        $this->assertSame(2, $result['expected_automatic_checks']);
        $this->assertSame(2, $result['observed_automatic_checks']);
        $this->travelBack();
    }
}
