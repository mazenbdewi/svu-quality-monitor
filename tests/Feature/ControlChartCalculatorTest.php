<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Services\ControlChartCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ControlChartCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-10'));
    }

    public function test_i_chart_calculates_center_line_and_limits(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100);
        $this->createCheck($service, '2026-07-08 09:00:00', 110);
        $this->createCheck($service, '2026-07-08 10:00:00', 130);
        $this->createCheck($service, '2026-07-08 11:00:00', 160);

        $chart = $this->calculate($service, 'i_chart');

        $this->assertSame('response_time_ms', $chart->metric_name);
        $this->assertSame('125.0000', $chart->center_line);
        $this->assertSame('178.1915', $chart->ucl);
        $this->assertSame('71.8085', $chart->lcl);
        $this->assertSame(4, $chart->points_count);
    }

    public function test_mr_chart_calculates_moving_ranges(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100);
        $this->createCheck($service, '2026-07-08 09:00:00', 110);
        $this->createCheck($service, '2026-07-08 10:00:00', 130);

        $chart = $this->calculate($service, 'mr_chart');

        $this->assertSame('15.0000', $chart->center_line);
        $this->assertSame('49.0050', $chart->ucl);
        $this->assertSame('0.0000', $chart->lcl);
        $this->assertSame(2, $chart->points_count);
        $this->assertSame(['10.0000', '20.0000'], $chart->points()->orderBy('point_time')->pluck('value')->all());
    }

    public function test_p_chart_calculates_failure_proportions(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100, isSuccess: false);
        $this->createCheck($service, '2026-07-08 08:05:00', 100, isSuccess: true);
        $this->createCheck($service, '2026-07-08 08:10:00', 100, isSuccess: true);
        $this->createCheck($service, '2026-07-08 09:00:00', 100, isSuccess: false);

        $chart = $this->calculate($service, 'p_chart');

        $this->assertSame('problematic_proportion', $chart->metric_name);
        $this->assertSame('0.5000', $chart->center_line);
        $this->assertSame(2, $chart->points_count);
        $this->assertSame(['0.3333', '1.0000'], $chart->points()->orderBy('point_time')->pluck('value')->all());
        $this->assertSame([3, 1], $chart->points()->orderBy('point_time')->pluck('sample_size')->all());
        $this->assertSame([1, 1], $chart->points()->orderBy('point_time')->pluck('failed_count')->all());
    }

    public function test_c_chart_counts_failures_per_bucket(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100, isSuccess: false);
        $this->createCheck($service, '2026-07-08 08:05:00', 100, isSuccess: true, isSlow: true);
        $this->createCheck($service, '2026-07-08 09:00:00', 100, isSuccess: true);

        $chart = $this->calculate($service, 'c_chart');

        $this->assertSame('failed_checks_count', $chart->metric_name);
        $this->assertSame('1.0000', $chart->center_line);
        $this->assertSame('4.0000', $chart->ucl);
        $this->assertSame('0.0000', $chart->lcl);
        $this->assertSame(['2.0000', '0.0000'], $chart->points()->orderBy('point_time')->pluck('value')->all());
    }

    public function test_u_chart_calculates_failures_per_check(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100, isSuccess: false);
        $this->createCheck($service, '2026-07-08 08:05:00', 100, isSuccess: true);
        $this->createCheck($service, '2026-07-08 09:00:00', 100, isSuccess: true);

        $chart = $this->calculate($service, 'u_chart');

        $this->assertSame('failures_per_check', $chart->metric_name);
        $this->assertSame('0.3333', $chart->center_line);
        $this->assertSame(2, $chart->points_count);
        $this->assertSame(['0.5000', '0.0000'], $chart->points()->orderBy('point_time')->pluck('value')->all());
    }

    public function test_recalculation_deletes_old_points_and_recreates_them(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, '2026-07-08 08:00:00', 100);
        $this->createCheck($service, '2026-07-08 09:00:00', 120);

        $firstChart = $this->calculate($service, 'i_chart');

        $this->assertSame(2, $firstChart->points()->count());

        $this->createCheck($service, '2026-07-08 10:00:00', 140);

        $secondChart = $this->calculate($service, 'i_chart');

        $this->assertSame($firstChart->id, $secondChart->id);
        $this->assertSame(3, $secondChart->points()->count());
        $this->assertDatabaseCount('control_charts', 1);
        $this->assertDatabaseCount('control_chart_points', 3);
    }

    public function test_out_of_control_points_are_detected(): void
    {
        $service = MonitoredService::factory()->create();

        for ($i = 0; $i < 100; $i++) {
            $this->createCheck($service, '2026-07-08 08:00:00', 100, isSuccess: false);
            $this->createCheck($service, '2026-07-08 09:00:00', 100, isSuccess: true);
        }

        $chart = $this->calculate($service, 'p_chart');

        $this->assertSame(2, $chart->out_of_control_count);
        $this->assertSame(['above_ucl', 'below_lcl'], $chart->points()->orderBy('point_time')->pluck('signal_type')->all());
    }

    public function test_command_calculates_control_charts_for_active_services(): void
    {
        $service = MonitoredService::factory()->create([
            'is_active' => true,
        ]);
        $this->createCheck($service, '2026-07-08 08:00:00', 100);
        $this->createCheck($service, '2026-07-08 09:00:00', 120);

        $this->artisan('control-charts:calculate --period=daily --date=2026-07-08 --chart=i_chart')
            ->expectsOutput('Control charts summary')
            ->expectsOutput('Services count: 1')
            ->expectsOutput('Chart types calculated: i_chart')
            ->expectsOutput('Charts created/updated: 1')
            ->expectsOutput('Errors count: 0')
            ->assertSuccessful();

        $this->assertDatabaseHas('control_charts', [
            'monitored_service_id' => $service->id,
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'points_count' => 2,
        ]);
    }

    private function calculate(MonitoredService $service, string $chartType)
    {
        return app(ControlChartCalculator::class)->calculate(
            $service,
            $chartType,
            Carbon::parse('2026-07-08 00:00:00'),
            Carbon::parse('2026-07-08 23:59:59'),
            'daily',
            'hourly',
        );
    }

    private function createCheck(
        MonitoredService $service,
        string $checkedAt,
        int $responseTimeMs,
        bool $isSuccess = true,
        bool $isSlow = false,
    ): void {
        $service->serviceChecks()->create([
            'checked_at' => Carbon::parse($checkedAt),
            'source' => 'automatic', 'check_type' => 'http',
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $responseTimeMs,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
        ]);
    }
}
