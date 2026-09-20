<?php

namespace App\Filament\Resources\MonitoredServices;

use App\Enums\MonitoringCheckType;
use App\Filament\Resources\MonitoredServices\Pages\CreateMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\EditMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\ListMonitoredServices;
use App\Filament\Resources\MonitoredServices\RelationManagers\SlaMetricsRelationManager;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Monitoring\MeasurementLimits;
use App\Services\AdministrativeAudit;
use App\Services\ServiceCheckRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('services.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('services.create') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('services.update') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('services.delete') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('services.delete') ?? false;
    }

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
                abort_unless(auth()->user()?->can('services.check_now'), 403);
                $check = app(ServiceCheckRunner::class)->run($record);

                static::sendCheckNotification($record, $check);
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make(__('ux.sections.service'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('monitoring.monitored_services.fields.name.label'))
                            ->placeholder(__('monitoring.monitored_services.fields.name.placeholder'))
                            ->helperText(__('monitoring.monitored_services.fields.name.helper'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('category')
                            ->label(__('monitoring.monitored_services.fields.category.label'))
                            ->placeholder(__('monitoring.monitored_services.fields.category.placeholder'))
                            ->helperText(__('monitoring.monitored_services.fields.category.helper'))
                            ->maxLength(255),
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
                    ]),
                Section::make(__('ux.sections.check'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('check_type')
                            ->label(__('monitoring.monitored_services.fields.check_type.label'))
                            ->options(__('ux.check_types'))
                            ->default('http')
                            ->live()
                            ->required()
                            ->native(false),
                        TextInput::make('check_interval_minutes')
                            ->label(__('monitoring.monitored_services.fields.check_interval_minutes.label'))
                            ->placeholder(__('monitoring.monitored_services.fields.check_interval_minutes.placeholder'))
                            ->helperText(__('monitoring.monitored_services.fields.check_interval_minutes.helper'))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(15),
                    ]),
                Section::make(__('ux.sections.connection'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('url')
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->label(fn (Get $get): string => in_array($get('check_type'), ['dns', 'ssl', 'tcp'], true) ? __('monitoring.monitored_services.fields.hostname.label') : __('monitoring.monitored_services.fields.url.label'))
                            ->placeholder(fn (Get $get): string => in_array($get('check_type'), ['dns', 'ssl', 'tcp'], true) ? 'example.org' : __('monitoring.monitored_services.fields.url.placeholder'))
                            ->helperText(fn (Get $get): string => in_array($get('check_type'), ['dns', 'ssl', 'tcp'], true) ? __('monitoring.monitored_services.fields.hostname.helper') : __('monitoring.monitored_services.fields.url.helper'))
                            ->required()
                            ->maxLength(2048),
                        Select::make('check_config.record_type')
                            ->label(__('monitoring.monitored_services.fields.dns_record_type.label'))
                            ->options(['A' => 'A', 'AAAA' => 'AAAA', 'CNAME' => 'CNAME', 'MX' => 'MX', 'TXT' => 'TXT'])->default('A')->native(false)
                            ->visible(fn (Get $get): bool => $get('check_type') === 'dns'),
                        TextInput::make('check_config.port')
                            ->label(__('monitoring.monitored_services.fields.port.label'))
                            ->integer()->minValue(1)->maxValue(65535)
                            ->visible(fn (Get $get): bool => in_array($get('check_type'), ['ssl', 'tcp'], true)),
                    ]),
                Section::make(__('ux.sections.technical'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()->collapsed()
                    ->visible(fn (Get $get): bool => $get('check_type') !== 'dns')
                    ->schema([
                        TextInput::make('expected_status_code')
                            ->label(__('monitoring.monitored_services.fields.expected_status_code.label'))
                            ->placeholder(__('monitoring.monitored_services.fields.expected_status_code.placeholder'))
                            ->helperText(__('monitoring.monitored_services.fields.expected_status_code.helper'))
                            ->required()
                            ->integer()
                            ->minValue(100)
                            ->maxValue(599)
                            ->default(200)
                            ->visible(fn (Get $get): bool => in_array($get('check_type'), ['http', 'api'], true)),
                        TextInput::make('expected_keyword')
                            ->label(__('monitoring.monitored_services.fields.expected_keyword.label'))
                            ->placeholder(__('monitoring.monitored_services.fields.expected_keyword.placeholder'))
                            ->helperText(__('monitoring.monitored_services.fields.expected_keyword.helper'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('check_type') === 'http'),
                        TextInput::make('check_config.timeout_seconds')
                            ->label(__('monitoring.monitored_services.fields.timeout_seconds.label'))
                            ->integer()->minValue(1)->maxValue(MeasurementLimits::MAX_SERVICE_TIMEOUT_SECONDS)->default(10)
                            ->visible(fn (Get $get): bool => in_array($get('check_type'), ['http', 'api', 'ssl', 'tcp'], true)),
                        Select::make('check_config.method')
                            ->label(__('monitoring.monitored_services.fields.api_method.label'))
                            ->options(['GET' => 'GET', 'POST' => 'POST'])->default('GET')->native(false)
                            ->visible(fn (Get $get): bool => $get('check_type') === 'api'),
                        KeyValue::make('check_config.headers')
                            ->label(__('monitoring.monitored_services.fields.api_headers.label'))
                            ->keyLabel(__('monitoring.monitored_services.fields.api_headers.key'))
                            ->valueLabel(__('monitoring.monitored_services.fields.api_headers.value'))
                            ->visible(fn (Get $get): bool => $get('check_type') === 'api'),
                        Textarea::make('check_config.body')
                            ->label(__('monitoring.monitored_services.fields.api_body.label'))
                            ->visible(fn (Get $get): bool => $get('check_type') === 'api'),
                        TextInput::make('check_config.json_path')
                            ->label(__('monitoring.monitored_services.fields.json_path.label'))
                            ->visible(fn (Get $get): bool => $get('check_type') === 'api'),
                        TextInput::make('check_config.json_expected_value')
                            ->label(__('monitoring.monitored_services.fields.json_expected_value.label'))
                            ->visible(fn (Get $get): bool => $get('check_type') === 'api'),
                    ]),
                Section::make(__('ux.sections.response'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => in_array($get('check_type'), ['http', 'api'], true))
                    ->schema([
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
                    ]),
                Section::make(__('ux.sections.confirmation'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->description(__('ux.help.confirmation'))
                    ->collapsible()->collapsed()
                    ->schema([
                        TextInput::make('failure_confirmation_count')
                            ->label(__('monitoring.monitored_services.fields.failure_confirmation_count.label'))
                            ->integer()->minValue(1)->default(2),
                        TextInput::make('recovery_confirmation_count')
                            ->label(__('monitoring.monitored_services.fields.recovery_confirmation_count.label'))
                            ->integer()->minValue(1)->default(2),
                    ]),
                Section::make(__('ux.sections.sla'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->description(__('ux.help.sla'))
                    ->schema([
                        Toggle::make('sla_enabled')
                            ->label(__('monitoring.sla.fields.enabled'))
                            ->live(),
                        TextInput::make('sla_target_percent')
                            ->label(__('monitoring.sla.fields.target'))
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0.01)
                            ->maxValue(100)
                            ->visible(fn (Get $get): bool => (bool) $get('sla_enabled')),
                    ]),
                Section::make(__('ux.sections.notifications'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('notifications_enabled')
                            ->label(__('monitoring.notifications.service_enabled'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['latestServiceCheck', 'openIncident', 'latestSlaMetric']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('monitoring.monitored_services.table.name'))->wrap()->limit(32)->tooltip(fn (MonitoredService $record): string => $record->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('check_type')
                    ->label(__('monitoring.monitored_services.fields.check_type.label'))
                    ->tooltip(fn (MonitoredService $record): string => __('ux.check_types.'.$record->check_type->value))
                    ->formatStateUsing(fn (MonitoringCheckType $state): string => strtoupper($state->value)),
                TextColumn::make('current_status')
                    ->label(__('monitoring.service_status.labels.current_status'))
                    ->badge()
                    ->icon(fn (MonitoredService $record): string => $record->current_status_icon)
                    ->getStateUsing(fn (MonitoredService $record): string => $record->current_status_label)
                    ->color(fn (MonitoredService $record): string => $record->current_status_color),
                TextColumn::make('under_maintenance')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.service_status.statuses.maintenance'))
                    ->badge()
                    ->getStateUsing(fn (MonitoredService $record): string => $record->is_under_maintenance ? __('monitoring.booleans.yes') : __('monitoring.booleans.no'))
                    ->color('gray'),
                TextColumn::make('last_response_time')
                    ->label(__('monitoring.service_status.labels.last_response_time'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->response_time_ms === null
                        ? '-'
                        : number_format((int) $record->latestServiceCheck->response_time_ms).' ms'),
                TextColumn::make('last_status_code')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.service_status.labels.last_status_code'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->latestServiceCheck?->status_code === null
                        ? '-'
                        : (string) $record->latestServiceCheck->status_code),
                TextColumn::make('last_problem_type')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.service_status.labels.last_problem_type'))
                    ->getStateUsing(fn (MonitoredService $record): string => $record->last_problem_type_label),
                TextColumn::make('has_open_incident')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.service_status.labels.open_incident'))
                    ->badge()
                    ->getStateUsing(fn (MonitoredService $record): string => $record->has_open_incident
                        ? __('monitoring.booleans.yes')
                        : __('monitoring.booleans.no'))
                    ->color(fn (MonitoredService $record): string => $record->has_open_incident ? 'danger' : 'gray'),
                TextColumn::make('latestServiceCheck.checked_at')->description(fn (MonitoredService $record): ?string => $record->latestServiceCheck?->checked_at?->format('H:i'))
                    ->label(__('monitoring.service_status.labels.last_check_at'))
                    ->dateTime('Y-m-d')
                    ->placeholder(__('monitoring.service_status.labels.not_checked_yet'))
                    ->sortable(),
                TextColumn::make('url')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.monitored_services.table.url'))
                    ->searchable()
                    ->limit(60)
                    ->copyable(),
                TextColumn::make('category')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.monitored_services.table.category'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sla_target_percent')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.sla.labels.target'))
                    ->formatStateUsing(fn (MonitoredService $record): string => $record->sla_enabled && $record->sla_target_percent ? number_format((float) $record->sla_target_percent, 2).'%' : '-'),
                TextColumn::make('latestSlaMetric.availability_percent')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.sla.labels.actual'))
                    ->formatStateUsing(fn (MonitoredService $record): string => $record->latestSlaMetric?->availability_percent === null ? '-' : number_format((float) $record->latestSlaMetric->availability_percent, 2).'%'),
                TextColumn::make('latestSlaMetric.status')
                    ->label(__('monitoring.sla.labels.status'))
                    ->tooltip(__('ux.help.sla'))
                    ->placeholder(__('monitoring.sla.statuses.no_data'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => __('monitoring.sla.statuses.'.($state ?? 'not_configured')))
                    ->color(fn (?string $state): string => match ($state) {
                        'met' => 'success', 'at_risk' => 'warning', 'breached' => 'danger', default => 'gray'
                    }),
                TextColumn::make('expected_status_code')
                    ->label(__('monitoring.monitored_services.table.expected_status_code'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('check_interval_minutes')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('monitoring.monitored_services.table.check_interval_minutes'))
                    ->formatStateUsing(fn (int|string|null $state): string => $state === null ? '-' : trans_choice('monitoring.units.minutes', (int) $state, ['count' => number_format((int) $state)])),
                IconColumn::make('is_active')
                    ->toggleable(isToggledHiddenByDefault: true)
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
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('ux.empty.services'))
            ->emptyStateDescription(__('ux.empty.services_help'))
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
                    DeleteBulkAction::make()->databaseTransaction()
                        ->using(function ($records, DeleteBulkAction $action): void {
                            foreach ($records as $record) {
                                app(AdministrativeAudit::class)->delete($record) || $action->reportBulkProcessingFailure();
                            }
                        })
                        ->authorize(fn (): bool => static::canDeleteAny())
                        ->label(__('monitoring.actions.delete_selected')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SlaMetricsRelationManager::class,
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
            'request_error' => __('monitoring.manual_check.errors.request_error'),
            'unknown' => __('monitoring.manual_check.errors.unknown'),
            default => __('monitoring.manual_check.errors.failed'),
        };
    }
}
