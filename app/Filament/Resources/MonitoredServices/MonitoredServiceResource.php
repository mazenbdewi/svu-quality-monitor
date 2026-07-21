<?php

namespace App\Filament\Resources\MonitoredServices;

use App\Filament\Resources\MonitoredServices\Pages\CreateMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\EditMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\ListMonitoredServices;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Services\ServiceCheckRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MonitoredServiceResource extends Resource
{
    protected static ?string $model = MonitoredService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('monitoring.monitored_services.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.monitored_services.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.monitored_services.resource.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public static function checkNowAction(): Action
    {
        return Action::make('check_now')
            ->label(__('monitoring.actions.check_now'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (MonitoredService $record): void {
                $check = app(ServiceCheckRunner::class)->run($record);

                static::sendCheckNotification($record, $check);
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('monitoring.monitored_services.fields.name.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.name.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.name.helper'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('url')
                    ->label(__('monitoring.monitored_services.fields.url.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.url.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.url.helper'))
                    ->required()
                    ->url()
                    ->maxLength(2048),
                TextInput::make('category')
                    ->label(__('monitoring.monitored_services.fields.category.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.category.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.category.helper'))
                    ->maxLength(255),
                TextInput::make('expected_status_code')
                    ->label(__('monitoring.monitored_services.fields.expected_status_code.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.expected_status_code.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.expected_status_code.helper'))
                    ->required()
                    ->integer()
                    ->minValue(100)
                    ->maxValue(599)
                    ->default(200),
                TextInput::make('expected_keyword')
                    ->label(__('monitoring.monitored_services.fields.expected_keyword.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.expected_keyword.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.expected_keyword.helper'))
                    ->maxLength(255),
                TextInput::make('check_interval_minutes')
                    ->label(__('monitoring.monitored_services.fields.check_interval_minutes.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.check_interval_minutes.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.check_interval_minutes.helper'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(15),
                TextInput::make('warning_response_ms')
                    ->label(__('monitoring.monitored_services.fields.warning_response_ms.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.warning_response_ms.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.warning_response_ms.helper'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(1500),
                TextInput::make('critical_response_ms')
                    ->label(__('monitoring.monitored_services.fields.critical_response_ms.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.critical_response_ms.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.critical_response_ms.helper'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(3000),
                Toggle::make('is_active')
                    ->label(__('monitoring.monitored_services.fields.is_active.label'))
                    ->helperText(__('monitoring.monitored_services.fields.is_active.helper'))
                    ->required()
                    ->default(true),
                Textarea::make('notes')
                    ->label(__('monitoring.monitored_services.fields.notes.label'))
                    ->placeholder(__('monitoring.monitored_services.fields.notes.placeholder'))
                    ->helperText(__('monitoring.monitored_services.fields.notes.helper'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['latestServiceCheck', 'openIncident']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('monitoring.monitored_services.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_status')
                    ->label(__('monitoring.service_status.labels.current_status'))
                    ->badge()
                    ->icon(fn (MonitoredService $record): string => $record->current_status_icon)
                    ->getStateUsing(fn (MonitoredService $record): string => $record->current_status_label)
                    ->color(fn (MonitoredService $record): string => $record->current_status_color),
                TextColumn::make('last_response_time')
                    ->label(__('monitoring.service_status.labels.last_response_time'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->response_time_ms === null
                        ? '-'
                        : number_format((int) $record->latestServiceCheck->response_time_ms).' ms'),
                TextColumn::make('last_status_code')
                    ->label(__('monitoring.service_status.labels.last_status_code'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->status_code === null
                        ? '-'
                        : (string) $record->latestServiceCheck->status_code),
                TextColumn::make('last_problem_type')
                    ->label(__('monitoring.service_status.labels.last_problem_type'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->last_problem_type_label),
                TextColumn::make('has_open_incident')
                    ->label(__('monitoring.service_status.labels.open_incident'))
                    ->badge()
                    ->getStateUsing(fn (MonitoredService $record): string => $record->has_open_incident
                        ? __('monitoring.booleans.yes')
                        : __('monitoring.booleans.no'))
                    ->color(fn (MonitoredService $record): string => $record->has_open_incident ? 'danger' : 'gray'),
                TextColumn::make('latestServiceCheck.checked_at')
                    ->label(__('monitoring.service_status.labels.last_check_at'))
                    ->dateTime()
                    ->placeholder(__('monitoring.service_status.labels.not_checked_yet'))
                    ->sortable(),
                TextColumn::make('url')
                    ->label(__('monitoring.monitored_services.table.url'))
                    ->searchable()
                    ->limit(60)
                    ->copyable(),
                TextColumn::make('category')
                    ->label(__('monitoring.monitored_services.table.category'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expected_status_code')
                    ->label(__('monitoring.monitored_services.table.expected_status_code'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('check_interval_minutes')
                    ->label(__('monitoring.monitored_services.table.check_interval_minutes'))
                    ->formatStateUsing(fn (int|string|null $state): string => $state === null ? '-' : trans_choice('monitoring.units.minutes', (int) $state, ['count' => number_format((int) $state)])),
                IconColumn::make('is_active')
                    ->label(__('monitoring.monitored_services.table.is_active'))
                    ->boolean(),
                TextColumn::make('expected_keyword')
                    ->label(__('monitoring.monitored_services.table.expected_keyword'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(40),
                TextColumn::make('warning_response_ms')
                    ->label(__('monitoring.monitored_services.table.warning_response_ms'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('critical_response_ms')
                    ->label(__('monitoring.monitored_services.table.critical_response_ms'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('monitoring.monitored_services.table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('monitoring.empty_states.no_data'))
            ->filters([
                Filter::make('active_services')
                    ->label(__('monitoring.monitored_services.filters.active'))
                    ->query(fn (Builder $query): Builder => $query->where('is_active', true)),
                Filter::make('inactive_services')
                    ->label(__('monitoring.monitored_services.filters.inactive'))
                    ->query(fn (Builder $query): Builder => $query->where('is_active', false)),
                Filter::make('healthy_services')
                    ->label(__('monitoring.service_status.filters.healthy'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestServiceCheck',
                        fn (Builder $query): Builder => $query
                            ->where('is_success', true)
                            ->where('is_slow', false),
                    )),
                Filter::make('slow_services')
                    ->label(__('monitoring.service_status.filters.slow'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestServiceCheck',
                        fn (Builder $query): Builder => $query
                            ->where('is_success', true)
                            ->where('is_slow', true),
                    )),
                Filter::make('down_services')
                    ->label(__('monitoring.service_status.filters.down'))
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestServiceCheck',
                        fn (Builder $query): Builder => $query->where('is_success', false),
                    )),
                Filter::make('open_incidents')
                    ->label(__('monitoring.service_status.filters.open_incidents'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('openIncident')),
                Filter::make('never_checked')
                    ->label(__('monitoring.service_status.filters.never_checked'))
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('serviceChecks')),
            ])
            ->recordActions([
                static::checkNowAction(),
                EditAction::make()
                    ->label(__('monitoring.actions.edit')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('monitoring.actions.delete_selected')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonitoredServices::route('/'),
            'create' => CreateMonitoredService::route('/create'),
            'edit' => EditMonitoredService::route('/{record}/edit'),
        ];
    }

    private static function sendCheckNotification(MonitoredService $service, ServiceCheck $check): void
    {
        if ($check->is_success && $check->is_slow) {
            Notification::make()
                ->warning()
                ->title(__('monitoring.manual_check.notifications.slow', [
                    'ms' => $check->response_time_ms ?? 0,
                ]))
                ->send();

            return;
        }

        if ($check->is_success) {
            Notification::make()
                ->success()
                ->title(__('monitoring.manual_check.notifications.success', [
                    'ms' => $check->response_time_ms ?? 0,
                ]))
                ->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title(__('monitoring.manual_check.notifications.failed', [
                'error' => static::checkFailureReason($service, $check),
            ]))
            ->send();
    }

    private static function checkFailureReason(MonitoredService $service, ServiceCheck $check): string
    {
        return match ($check->error_type) {
            'unexpected_status_code' => __('monitoring.manual_check.errors.unexpected_status_code', [
                'expected' => $service->expected_status_code,
                'actual' => $check->status_code ?? __('monitoring.manual_check.errors.empty_value'),
            ]),
            'keyword_missing' => __('monitoring.manual_check.errors.keyword_missing'),
            'timeout' => __('monitoring.manual_check.errors.timeout'),
            'connection_error' => __('monitoring.manual_check.errors.connection_error'),
            'request_error' => $check->error_message ?: __('monitoring.manual_check.errors.request_error'),
            'unknown' => $check->error_message ?: __('monitoring.manual_check.errors.unknown'),
            default => $check->error_message ?: __('monitoring.manual_check.errors.failed'),
        };
    }
}
