<?php

namespace App\Filament\Widgets;

use App\Models\ReliabilityMetric;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestReliabilityMetricsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('monitoring.dashboard.widgets.latest_reliability_metrics');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ReliabilityMetric::query()
                ->where('period_type', 'daily')
                ->with('monitoredService')
                ->latest('calculated_at')
                ->limit(5))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading(__('monitoring.dashboard.empty.reliability_metrics'))
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.dashboard.columns.service_name')),
                TextColumn::make('availability_percent')
                    ->label(__('monitoring.dashboard.columns.availability_percent'))
                    ->placeholder(__('monitoring.reliability_insufficient_data'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state === null ? __('monitoring.reliability_insufficient_data') : number_format((float) $state, 4).'%')
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state >= 99 => 'success',
                        (float) $state >= 95 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('mtbf_minutes')
                    ->label(__('monitoring.dashboard.columns.mtbf_minutes'))
                    ->formatStateUsing(fn (int|float|string|null $state): string => $state === null ? __('monitoring.dashboard.empty.value') : trans_choice('monitoring.units.minutes', (int) round((float) $state), ['count' => number_format((float) $state, 2)])),
                TextColumn::make('mttr_minutes')
                    ->label(__('monitoring.dashboard.columns.mttr_minutes'))
                    ->formatStateUsing(fn (int|float|string|null $state): string => $state === null ? __('monitoring.dashboard.empty.value') : trans_choice('monitoring.units.minutes', (int) round((float) $state), ['count' => number_format((float) $state, 2)])),
                TextColumn::make('incidents_count')
                    ->label(__('monitoring.dashboard.columns.incidents_count'))
                    ->sortable(),
                TextColumn::make('calculated_at')
                    ->label(__('monitoring.dashboard.columns.calculated_at'))
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
