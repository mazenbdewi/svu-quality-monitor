<?php

namespace App\Filament\Widgets;

use App\Models\MonitoredService;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class WorstServicesTodayWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('monitoring.dashboard.widgets.worst_services_today');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getWorstServicesQuery())
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('monitoring.dashboard.empty_states.no_checks_today'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('monitoring.dashboard.columns.service_name'))
                    ->searchable(),
                TextColumn::make('today_checks_count')
                    ->label(__('monitoring.dashboard.columns.total_checks'))
                    ->sortable(),
                TextColumn::make('failed_checks_count')
                    ->label(__('monitoring.dashboard.columns.failed_checks'))
                    ->badge()
                    ->color(fn (int|string|null $state): string => (int) $state > 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('slow_checks_count')
                    ->label(__('monitoring.dashboard.columns.slow_checks'))
                    ->badge()
                    ->color(fn (int|string|null $state): string => (int) $state > 0 ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('problematic_checks_count')
                    ->label(__('monitoring.dashboard.columns.problematic_checks'))
                    ->badge()
                    ->color(fn (int|string|null $state): string => (int) $state > 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('average_response_time_today')
                    ->label(__('monitoring.dashboard.columns.average_response_time'))
                    ->formatStateUsing(fn ($state): string => $state === null ? __('monitoring.dashboard.empty.value') : number_format((float) $state).' ms'),
            ]);
    }

    public static function getWorstServicesQuery(): Builder
    {
        return MonitoredService::query()
            ->whereHas('serviceChecks', fn (Builder $query): Builder => $query
                ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()]))
            ->withCount([
                'serviceChecks as today_checks_count' => fn (Builder $query): Builder => $query
                    ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()]),
                'serviceChecks as failed_checks_count' => fn (Builder $query): Builder => $query
                    ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()])
                    ->where('is_success', false),
                'serviceChecks as slow_checks_count' => fn (Builder $query): Builder => $query
                    ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()])
                    ->where('is_slow', true),
                'serviceChecks as problematic_checks_count' => fn (Builder $query): Builder => $query
                    ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()])
                    ->where(fn (Builder $query): Builder => $query
                        ->where('is_success', false)
                        ->orWhere('is_slow', true)),
            ])
            ->withAvg([
                'serviceChecks as average_response_time_today' => fn (Builder $query): Builder => $query
                    ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()])
                    ->whereNotNull('response_time_ms'),
            ], 'response_time_ms')
            ->orderByDesc('problematic_checks_count')
            ->orderByDesc('average_response_time_today')
            ->limit(5);
    }
}
