<?php

namespace App\Filament\Widgets;

use App\Models\ControlChartPoint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestOutOfControlPointsWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('monitoring.dashboard.widgets.latest_out_of_control_points');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getLatestOutOfControlPointsQuery())
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('monitoring.dashboard.empty_states.no_out_of_control_points'))
            ->columns([
                TextColumn::make('controlChart.monitoredService.name')
                    ->label(__('monitoring.dashboard.columns.service_name')),
                TextColumn::make('controlChart.chart_type')
                    ->label(__('monitoring.dashboard.columns.chart_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? __("monitoring.dashboard.chart_types.{$state}") : ''),
                TextColumn::make('controlChart.metric_name')
                    ->label(__('monitoring.dashboard.columns.metric_name'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __("monitoring.metrics.{$state}") : ''),
                TextColumn::make('point_time')
                    ->label(__('monitoring.dashboard.columns.point_time'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('value')
                    ->label(__('monitoring.dashboard.columns.value'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('ucl')
                    ->label(__('monitoring.dashboard.columns.ucl'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('lcl')
                    ->label(__('monitoring.dashboard.columns.lcl'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('signal_type')
                    ->label(__('monitoring.dashboard.columns.signal_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? __("monitoring.dashboard.signal_types.{$state}") : '')
                    ->color('danger'),
            ]);
    }

    public static function getLatestOutOfControlPointsQuery(): Builder
    {
        return ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->with('controlChart.monitoredService')
            ->latest('point_time')
            ->limit(5);
    }
}
