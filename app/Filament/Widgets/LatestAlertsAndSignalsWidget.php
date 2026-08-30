<?php

namespace App\Filament\Widgets;

use App\Models\ControlChartPoint;
use App\Models\ServiceIncident;
use Filament\Widgets\Widget;

class LatestAlertsAndSignalsWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.latest-alerts-and-signals-widget';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $incidents = ServiceIncident::query()->where('status', 'open')->with('monitoredService')->latest('started_at')->limit(5)->get()
            ->map(fn (ServiceIncident $incident): array => ['time' => $incident->started_at, 'service' => $incident->monitoredService?->name, 'type' => __('monitoring.dashboard.alert_types.incident'), 'details' => __('monitoring.dashboard.alert_types.open_incident'), 'color' => 'danger']);
        $signals = ControlChartPoint::query()->where('is_out_of_control', true)->with('controlChart.monitoredService')->latest('point_time')->limit(5)->get()
            ->map(fn (ControlChartPoint $point): array => ['time' => $point->point_time, 'service' => $point->controlChart?->monitoredService?->name, 'type' => __('monitoring.dashboard.alert_types.signal'), 'details' => $point->signal_type ? __("monitoring.dashboard.signal_types.{$point->signal_type}") : __('monitoring.dashboard.empty.value'), 'color' => 'warning']);

        return ['alerts' => $incidents->concat($signals)->sortByDesc('time')->take(8)];
    }
}
