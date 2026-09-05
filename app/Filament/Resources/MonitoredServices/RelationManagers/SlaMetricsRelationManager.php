<?php

namespace App\Filament\Resources\MonitoredServices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlaMetricsRelationManager extends RelationManager
{
    protected static string $relationship = 'slaMetrics';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('monitoring.executive.sla.history');
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('period_start', 'desc')->columns([
            TextColumn::make('period_start')->label(__('monitoring.executive.sla.month'))->date('Y-m'),
            TextColumn::make('target_percent')->label(__('monitoring.sla.labels.target'))->suffix('%'),
            TextColumn::make('availability_percent')->label(__('monitoring.sla.labels.actual'))->suffix('%'),
            TextColumn::make('eligible_observation_seconds')->label(__('monitoring.executive.sla.eligible'))->formatStateUsing(fn ($state) => gmdate('H:i:s', (int) $state)),
            TextColumn::make('planned_maintenance_seconds')->label(__('monitoring.executive.sla.maintenance'))->formatStateUsing(fn ($state) => gmdate('H:i:s', (int) $state)),
            TextColumn::make('unplanned_downtime_seconds')->label(__('monitoring.executive.sla.downtime'))->formatStateUsing(fn ($state) => gmdate('H:i:s', (int) $state)),
            TextColumn::make('allowed_downtime_seconds')->label(__('monitoring.executive.sla.allowed'))->formatStateUsing(fn ($state) => gmdate('H:i:s', (int) $state)),
            TextColumn::make('error_budget_consumed_percent')->label(__('monitoring.executive.sla.budget_used'))->formatStateUsing(fn ($state) => $state === null ? '-' : number_format((float) $state, 1).'%'),
            TextColumn::make('status')->label(__('monitoring.sla.labels.status'))->badge()->formatStateUsing(fn ($state) => __('monitoring.sla.statuses.'.$state))->color(fn ($state) => match ($state) {
                'met' => 'success', 'at_risk' => 'warning', 'breached' => 'danger', default => 'gray'
            }),
        ]);
    }
}
