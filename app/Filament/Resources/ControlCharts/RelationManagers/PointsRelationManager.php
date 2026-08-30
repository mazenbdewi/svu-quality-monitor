<?php

namespace App\Filament\Resources\ControlCharts\RelationManagers;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PointsRelationManager extends RelationManager
{
    protected static string $relationship = 'points';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('monitoring.control_charts.points.resource.navigation_label');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('point_time', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('point_time')
                    ->label(__('monitoring.control_charts.points.table.point_time'))
                    ->dateTime('d/m/Y H:i')
                    ->alignCenter()
                    ->extraAttributes(['dir' => 'ltr', 'class' => '[unicode-bidi:isolate] text-center', 'style' => 'text-align: center; unicode-bidi: isolate;'])
                    ->sortable(),
                TextColumn::make('value')
                    ->label(__('monitoring.control_charts.points.table.value'))
                    ->numeric(decimalPlaces: 2)
                    ->alignCenter()
                    ->extraAttributes(['dir' => 'ltr', 'class' => '[unicode-bidi:isolate] text-center', 'style' => 'text-align: center; unicode-bidi: isolate;'])
                    ->sortable(),
                TextColumn::make('difference_from_center_line')
                    ->label(__('monitoring.control_charts.points.table.difference_from_center_line'))
                    ->state(fn ($record): ?float => $record->center_line === null ? null : (float) $record->value - (float) $record->center_line)
                    ->formatStateUsing(fn (mixed $state): string => $state === null
                        ? __('monitoring.dashboard.empty.value')
                        : ((float) $state === 0.0 ? '0.00' : sprintf('%+.2f', (float) $state)))
                    ->alignCenter()
                    ->extraAttributes(['dir' => 'ltr', 'class' => '[unicode-bidi:isolate] text-center', 'style' => 'text-align: center; unicode-bidi: isolate;']),
                TextColumn::make('status')
                    ->label(__('monitoring.control_charts.points.table.status'))
                    ->state(fn ($record): string => $record->is_out_of_control ? 'out_of_control' : ($record->signal_type ? 'warning' : 'normal'))
                    ->formatStateUsing(fn (string $state): string => __("monitoring.control_charts.points.statuses.{$state}"))
                    ->badge()
                    ->alignCenter()
                    ->color(fn (string $state): string => match ($state) {
                        'out_of_control' => 'danger',
                        'warning' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('signal_type')
                    ->label(__('monitoring.control_charts.points.table.signal_type'))
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ControlChartResource::signalTypeOptions()[$state] ?? $state) : '')
                    ->color(fn (?string $state): string => $state ? 'danger' : 'gray'),
            ]);
    }
}
