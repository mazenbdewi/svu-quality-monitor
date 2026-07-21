<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use App\Models\ServiceIncident;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestOpenIncidentsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('monitoring.dashboard.widgets.latest_open_incidents');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ServiceIncident::query()
                ->where('status', 'open')
                ->with('monitoredService')
                ->latest('started_at')
                ->limit(5))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('monitoring.dashboard.empty_states.no_open_incidents'))
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.dashboard.columns.service_name'))
                    ->searchable(),
                TextColumn::make('incident_type')
                    ->label(__('monitoring.dashboard.columns.incident_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ServiceIncidentResource::incidentTypeOptions()[$state] ?? $state) : ''),
                TextColumn::make('severity')
                    ->label(__('monitoring.dashboard.columns.severity'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (ServiceIncidentResource::severityOptions()[$state] ?? $state) : '')
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'danger',
                        'medium' => 'warning',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('started_at')
                    ->label(__('monitoring.dashboard.columns.started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration_so_far')
                    ->label(__('monitoring.dashboard.columns.duration'))
                    ->state(fn (ServiceIncident $record): string => trans_choice('monitoring.units.minutes', (int) $record->started_at->diffInMinutes(now()), ['count' => number_format((int) $record->started_at->diffInMinutes(now()))])),
            ]);
    }
}
