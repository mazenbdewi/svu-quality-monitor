<?php

namespace Tests\Feature;

use App\Filament\Widgets\CurrentServiceStatusWidget;
use App\Filament\Widgets\LatestOutOfControlPointsWidget;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\ResearchFindingsWidget;
use App\Filament\Widgets\WorstServicesTodayWidget;
use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stats_widget_shows_the_dashboard_summary_cards(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');

        $activeService = MonitoredService::factory()->create(['is_active' => true]);
        MonitoredService::factory()->create(['is_active' => false]);

        ServiceCheck::query()->create([
            'monitored_service_id' => $activeService->id,
            'checked_at' => now(),
            'status_code' => 500,
            'response_time_ms' => 900,
            'is_success' => false,
            'is_slow' => true,
        ]);

        ServiceIncident::query()->create([
            'monitored_service_id' => $activeService->id,
            'started_at' => now()->subHour(),
            'incident_type' => 'down',
            'severity' => 'critical',
            'status' => 'open',
        ]);

        $controlChart = ControlChart::query()->create([
            'monitored_service_id' => $activeService->id,
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'period_type' => 'daily',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
        ]);

        $controlChart->points()->create([
            'point_time' => now(),
            'value' => 5000,
            'is_out_of_control' => true,
            'signal_type' => 'above_ucl',
        ]);

        $stats = $this->statsByLabel(app(OverviewStatsWidget::class));

        $this->assertSame('2', $stats[__('monitoring.dashboard.stats.total_services')]);
        $this->assertSame('0', $stats[__('monitoring.dashboard.stats.available_now')]);
        $this->assertSame('1', $stats[__('monitoring.dashboard.stats.today_checks')]);
        $this->assertSame('1', $stats[__('monitoring.dashboard.stats.today_out_of_control_points')]);
    }

    public function test_research_findings_widget_renders_on_the_dashboard(): void
    {
        Livewire::test(ResearchFindingsWidget::class)
            ->assertSee(__('monitoring.interpretation.sections.key_findings'))
            ->assertSee(__('monitoring.dashboard.research_cards.performance'))
            ->assertSee(__('monitoring.dashboard.research_cards.reliability'))
            ->assertSee(__('monitoring.dashboard.research_cards.spc'));
    }

    public function test_worst_services_query_counts_failed_or_slow_checks_as_problematic(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');

        $service = MonitoredService::factory()->create(['is_active' => true]);

        $this->createCheck($service, isSuccess: true, isSlow: false);
        $this->createCheck($service, isSuccess: false, isSlow: false);
        $this->createCheck($service, isSuccess: true, isSlow: true);

        $record = WorstServicesTodayWidget::getWorstServicesQuery()->first();

        $this->assertNotNull($record);
        $this->assertSame(3, (int) $record->today_checks_count);
        $this->assertSame(1, (int) $record->failed_checks_count);
        $this->assertSame(1, (int) $record->slow_checks_count);
        $this->assertSame(2, (int) $record->problematic_checks_count);
    }

    public function test_current_service_status_widget_query_uses_stored_data_only(): void
    {
        Http::preventStrayRequests();

        $service = MonitoredService::factory()->create(['is_active' => true]);
        $this->createCheck($service, isSuccess: false, isSlow: false);

        $records = CurrentServiceStatusWidget::getCurrentServiceStatusQuery()->get();

        $this->assertCount(1, $records);
        $this->assertSame('down', $records->first()->current_status);
    }

    public function test_latest_out_of_control_points_query_loads_related_service(): void
    {
        Carbon::setTestNow('2026-07-08 12:00:00');

        $service = MonitoredService::factory()->create(['name' => 'SVU Portal']);
        $controlChart = ControlChart::query()->create([
            'monitored_service_id' => $service->id,
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'period_type' => 'daily',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
        ]);

        ControlChartPoint::query()->create([
            'control_chart_id' => $controlChart->id,
            'point_time' => now(),
            'value' => 5000,
            'ucl' => 3000,
            'lcl' => 0,
            'is_out_of_control' => true,
            'signal_type' => 'above_ucl',
        ]);

        $record = LatestOutOfControlPointsWidget::getLatestOutOfControlPointsQuery()->first();

        $this->assertNotNull($record);
        $this->assertTrue($record->relationLoaded('controlChart'));
        $this->assertSame('SVU Portal', $record->controlChart->monitoredService->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function statsByLabel(OverviewStatsWidget $widget): array
    {
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        $stats = [];

        foreach ($method->invoke($widget) as $stat) {
            $stats[(string) $stat->getLabel()] = $stat->getValue();
        }

        return $stats;
    }

    private function createCheck(MonitoredService $service, bool $isSuccess, bool $isSlow): void
    {
        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'checked_at' => now(),
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $isSlow ? 2500 : 500,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
        ]);
    }
}
