<?php

namespace App\Filament\Resources\ControlCharts\RelationManagers;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
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
            ->defaultSort('point_time')
            ->columns([
                TextColumn::make('point_time')
                    ->label(__('monitoring.control_charts.points.table.point_time'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('value')
                    ->label(__('monitoring.control_charts.points.table.value'))
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                TextColumn::make('center_line')
                    ->label(__('monitoring.control_charts.points.table.center_line'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('ucl')
                    ->label(__('monitoring.control_charts.points.table.ucl'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('lcl')
                    ->label(__('monitoring.control_charts.points.table.lcl'))
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('sample_size')
                    ->label(__('monitoring.control_charts.points.table.sample_size'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label(__('monitoring.control_charts.points.table.failed_count'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                IconColumn::make('is_out_of_control')
                    ->label(__('monitoring.control_charts.points.table.is_out_of_control'))
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->sortable(),
                TextColumn::make('signal_type')
                    ->label(__('monitoring.control_charts.points.table.signal_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ControlChartResource::signalTypeOptions()[$state] ?? $state) : '')
                    ->color(fn (?string $state): string => $state ? 'danger' : 'gray'),
                TextColumn::make('note')
                    ->label(__('monitoring.control_charts.points.table.note'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(50),
            ]);
    }
}
