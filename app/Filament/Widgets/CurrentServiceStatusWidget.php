<?php

namespace App\Filament\Widgets;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CurrentServiceStatusWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return __('monitoring.dashboard.widgets.current_service_status');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::getCurrentServiceStatusQuery())
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('monitoring.dashboard.empty_states.no_services'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('monitoring.dashboard.columns.service_name'))
                    ->searchable(),
                TextColumn::make('current_status')
                    ->label(__('monitoring.dashboard.columns.current_status'))
                    ->badge()
                    ->icon(fn (MonitoredService $record): string => $record->current_status_icon)
                    ->getStateUsing(fn (MonitoredService $record): string => $record->current_status_label)
                    ->color(fn (MonitoredService $record): string => $record->current_status_color),
                TextColumn::make('latestServiceCheck.checked_at')
                    ->label(__('monitoring.dashboard.columns.last_check'))
                    ->dateTime()
                    ->placeholder(__('monitoring.service_status.labels.not_checked_yet')),
                TextColumn::make('last_response_time')
                    ->label(__('monitoring.dashboard.columns.last_response_time'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->response_time_ms === null
                        ? '-'
                        : number_format((int) $record->latestServiceCheck->response_time_ms).' ms'),
                TextColumn::make('last_status_code')
                    ->label(__('monitoring.dashboard.columns.last_status_code'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->status_code === null
                        ? '-'
                        : (string) $record->latestServiceCheck->status_code),
                TextColumn::make('has_open_incident')
                    ->label(__('monitoring.dashboard.columns.open_incident'))
                    ->badge()
                    ->getStateUsing(fn (MonitoredService $record): string => $record->has_open_incident
                        ? __('monitoring.booleans.yes')
                        : __('monitoring.booleans.no'))
                    ->color(fn (MonitoredService $record): string => $record->has_open_incident ? 'danger' : 'gray'),
            ]);
    }

    public static function getCurrentServiceStatusQuery(): Builder
    {
        $latestCheckId = 'select id from service_checks where service_checks.monitored_service_id = monitored_services.id order by checked_at desc limit 1';
        $latestSuccess = 'select is_success from service_checks where service_checks.monitored_service_id = monitored_services.id order by checked_at desc limit 1';
        $latestSlow = 'select is_slow from service_checks where service_checks.monitored_service_id = monitored_services.id order by checked_at desc limit 1';

        return MonitoredService::query()
            ->where('is_active', true)
            ->with(['latestServiceCheck', 'openIncident'])
            ->orderByRaw("
                case
                    when ({$latestSuccess}) is false then 1
                    when ({$latestSuccess}) is true and ({$latestSlow}) is true then 2
                    when exists (
                        select 1 from service_incidents
                        where service_incidents.monitored_service_id = monitored_services.id
                        and service_incidents.status = 'open'
                    ) then 3
                    when ({$latestCheckId}) is null then 4
                    else 5
                end
            ")
            ->orderByDesc(
                ServiceCheck::query()
                    ->select('checked_at')
                    ->whereColumn('service_checks.monitored_service_id', 'monitored_services.id')
                    ->latest('checked_at')
                    ->limit(1)
            )
            ->limit(10);
    }
}
