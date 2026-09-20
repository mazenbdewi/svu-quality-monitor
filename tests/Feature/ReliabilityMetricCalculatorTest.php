<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Services\ReliabilityMetricCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReliabilityMetricCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10 00:00:00');
    }

    private function fullCoverage(MonitoredService $service): void
    {
        for ($hour = 0; $hour < 24; $hour++) {
            $this->createCheck($service, sprintf('2026-07-08 %02d:00:00', $hour), true, false);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_calculates_total_and_failed_checks_including_slow_checks(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => '2026-07-08 00:00:00', 'check_interval_minutes' => 60]);
        [$start, $end] = $this->dailyPeriod();
        $this->createCheck($service, '2026-07-09 00:00:00', true, false);

        $this->createCheck($service, '2026-07-08 08:00:00', isSuccess: true, isSlow: false);
        $this->createCheck($service, '2026-07-08 09:00:00', isSuccess: false, isSlow: false);
        $this->createCheck($service, '2026-07-08 10:00:00', isSuccess: true, isSlow: true);

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, $start, $end);

        $this->assertSame(3, $metric->total_checks);
        $this->assertSame(1, $metric->successful_checks);
        $this->assertSame(2, $metric->failed_checks);
    }

    public function test_it_calculates_downtime_from_closed_incidents(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => '2026-07-08 00:00:00', 'check_interval_minutes' => 60]);
        [$start, $end] = $this->dailyPeriod();
        $this->createCheck($service, '2026-07-09 00:00:00', true, false);

        $this->fullCoverage($service);

        $service->serviceIncidents()->create([
            'confirmed_at' => Carbon::parse('2026-07-08 00:00:00'),
            'started_at' => Carbon::parse('2026-07-08 10:00:00'),
            'ended_at' => Carbon::parse('2026-07-08 10:30:00'),
            'duration_minutes' => 30,
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'closed',
        ]);

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, $start, $end);

        $this->assertSame(1, $metric->incidents_count);
        $this->assertSame(30, $metric->downtime_minutes);
        $this->assertSame(1410, $metric->uptime_minutes);
    }

    public function test_it_counts_open_incident_downtime_until_period_end(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => '2026-07-08 00:00:00', 'check_interval_minutes' => 60]);
        [$start, $end] = $this->dailyPeriod();
        $this->createCheck($service, '2026-07-09 00:00:00', true, false);

        $this->fullCoverage($service);

        $service->serviceIncidents()->create([
            'confirmed_at' => Carbon::parse('2026-07-08 00:00:00'),
            'started_at' => Carbon::parse('2026-07-08 23:30:00'),
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'open',
        ]);

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, $start, $end);

        $this->assertSame(30, $metric->downtime_minutes);
        $this->assertSame(1410, $metric->uptime_minutes);
    }

    public function test_it_avoids_duplicate_metrics_when_recalculated(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');

        $service = MonitoredService::factory()->create(['created_at' => '2026-07-08 00:00:00', 'check_interval_minutes' => 60]);
        [$start, $end] = $this->dailyPeriod();
        $this->createCheck($service, '2026-07-09 00:00:00', true, false);

        $calculator = app(ReliabilityMetricCalculator::class);

        $firstMetric = $calculator->calculateForService($service, $start, $end);

        $this->createCheck($service, '2026-07-08 08:00:00', isSuccess: true, isSlow: false);

        $secondMetric = $calculator->calculateForService($service, $start, $end);

        $this->assertSame($firstMetric->id, $secondMetric->id);
        $this->assertDatabaseCount('reliability_metrics', 1);
        $this->assertSame(1, $secondMetric->total_checks);
    }

    public function test_it_calculates_availability_mtbf_mttr_and_failure_rate(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => '2026-07-08 00:00:00', 'check_interval_minutes' => 60]);
        [$start, $end] = $this->dailyPeriod();
        $this->createCheck($service, '2026-07-09 00:00:00', true, false);

        $this->fullCoverage($service);

        $service->serviceIncidents()->create([
            'confirmed_at' => Carbon::parse('2026-07-08 00:00:00'),
            'started_at' => Carbon::parse('2026-07-08 02:00:00'),
            'ended_at' => Carbon::parse('2026-07-08 02:30:00'),
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'closed',
        ]);
        $service->serviceIncidents()->create([
            'confirmed_at' => Carbon::parse('2026-07-08 00:00:00'),
            'started_at' => Carbon::parse('2026-07-08 14:00:00'),
            'ended_at' => Carbon::parse('2026-07-08 14:30:00'),
            'incident_type' => 'timeout',
            'severity' => 'critical',
            'status' => 'closed',
        ]);

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, $start, $end);

        $this->assertSame(60, $metric->downtime_minutes);
        $this->assertSame(1380, $metric->uptime_minutes);
        $this->assertSame('95.8333', $metric->availability_percent);
        $this->assertSame('690.00', $metric->mtbf_minutes);
        $this->assertSame('30.00', $metric->mttr_minutes);
        $this->assertSame('0.00144928', $metric->failure_rate);
    }

    public function test_command_calculates_metrics_for_active_services(): void
    {
        $service = MonitoredService::factory()->create([
            'is_active' => true,
            'created_at' => '2026-07-08 00:00:00',
            'check_interval_minutes' => 60,
        ]);

        $this->createCheck($service, '2026-07-09 00:00:00', true, false);
        $this->createCheck($service, '2026-07-08 08:00:00', isSuccess: true, isSlow: false);

        $this->artisan('reliability:calculate --period=daily --date=2026-07-08')
            ->expectsOutput('Reliability metrics summary')
            ->expectsOutput('Period type: daily')
            ->expectsOutput('Services count: 1')
            ->expectsOutput('Calculated count: 1')
            ->expectsOutput('Errors count: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('reliability_metrics', [
            'monitored_service_id' => $service->id,
            'period_type' => 'daily',
            'total_checks' => 1,
            'successful_checks' => 1,
            'failed_checks' => 0,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dailyPeriod(): array
    {
        return [
            Carbon::parse('2026-07-08 00:00:00'),
            Carbon::parse('2026-07-09 00:00:00'),
        ];
    }

    private function createCheck(
        MonitoredService $service,
        string $checkedAt,
        bool $isSuccess,
        bool $isSlow,
    ): void {
        $service->serviceChecks()->create([
            'source' => 'automatic',
            'checked_at' => Carbon::parse($checkedAt),
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $isSlow ? 2000 : 100,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
        ]);
    }
}
