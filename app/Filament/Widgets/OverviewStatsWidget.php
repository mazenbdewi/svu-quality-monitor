<?php

namespace App\Filament\Widgets;

use App\Models\ControlChartPoint;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * @var int | array<string, ?int> | null
     */
    protected int|array|null $columns = [
        '@xl' => 7,
        '!@lg' => 2,
    ];

    protected function getHeading(): ?string
    {
        return __('monitoring.dashboard.widgets.overview');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        [$start, $end] = $this->todayRange();

        $totalServices = MonitoredService::query()->count();
        $availableServices = MonitoredService::query()
            ->where('is_active', true)
            ->whereHas('latestServiceCheck', fn ($query) => $query
                ->where('is_success', true)
                ->where('is_slow', false))
            ->count();
        $todayChecks = ServiceCheck::query()->whereBetween('checked_at', [$start, $end])->count();
        $todayOutOfControlPoints = ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->whereBetween('point_time', [$start, $end])
            ->count();
        $averageAvailability = $this->averageLatestDailyAvailability();
        $activeMaintenance = MaintenanceWindow::query()->activeAt(now())->with('monitoredServices:id')->get();
        $servicesUnderMaintenance = $activeMaintenance->contains('applies_to_all_services', true)
            ? $totalServices
            : $activeMaintenance->flatMap(fn (MaintenanceWindow $window) => $window->monitoredServices->pluck('id'))->unique()->count();

        return [
            Stat::make(__('monitoring.dashboard.stats.total_services'), number_format($totalServices))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('info'),
            Stat::make(__('monitoring.dashboard.stats.available_now'), number_format($availableServices))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color($availableServices > 0 ? 'success' : 'warning'),
            Stat::make(__('monitoring.dashboard.stats.today_checks'), number_format($todayChecks))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($todayChecks > 0 ? 'success' : 'warning'),
            Stat::make(__('monitoring.dashboard.stats.today_out_of_control_points'), number_format($todayOutOfControlPoints))
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color($todayOutOfControlPoints > 0 ? 'danger' : 'success'),
            Stat::make(__('monitoring.dashboard.stats.average_availability'), $averageAvailability === null ? __('monitoring.dashboard.empty.value') : number_format($averageAvailability, 2).'%')
                ->icon(Heroicon::OutlinedChartBar)
                ->color(match (true) {
                    $averageAvailability === null => 'gray',
                    $averageAvailability >= 99 => 'success',
                    $averageAvailability >= 95 => 'warning',
                    default => 'danger',
                }),
            Stat::make(__('monitoring.dashboard.stats.active_maintenance_windows'), number_format($activeMaintenance->count()))
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color($activeMaintenance->isEmpty() ? 'gray' : 'warning'),
            Stat::make(__('monitoring.dashboard.stats.services_under_maintenance'), number_format($servicesUnderMaintenance))
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color($servicesUnderMaintenance === 0 ? 'gray' : 'warning'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function todayRange(): array
    {
        return [
            now()->startOfDay(),
            now()->endOfDay(),
        ];
    }

    private function averageLatestDailyAvailability(): ?float
    {
        $todayAverage = ReliabilityMetric::query()
            ->where('period_type', 'daily')
            ->whereDate('period_start', today())
            ->avg('availability_percent');

        if ($todayAverage !== null) {
            return (float) $todayAverage;
        }

        $activeServiceIds = MonitoredService::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($activeServiceIds->isEmpty()) {
            return null;
        }

        $metrics = ReliabilityMetric::query()
            ->whereIn('id', function ($query) use ($activeServiceIds): void {
                $query->selectRaw('MAX(id)')
                    ->from('reliability_metrics')
                    ->where('period_type', 'daily')
                    ->whereIn('monitored_service_id', $activeServiceIds)
                    ->groupBy('monitored_service_id');
            })
            ->pluck('availability_percent');

        return $metrics->isEmpty() ? null : (float) $metrics->avg();
    }
}
