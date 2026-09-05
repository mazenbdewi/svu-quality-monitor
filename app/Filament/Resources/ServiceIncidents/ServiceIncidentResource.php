<?php

namespace App\Filament\Resources\ServiceIncidents;

use App\Filament\Resources\ServiceIncidents\Pages\CreateServiceIncident;
use App\Filament\Resources\ServiceIncidents\Pages\EditServiceIncident;
use App\Filament\Resources\ServiceIncidents\Pages\ListServiceIncidents;
use App\Models\ServiceIncident;
use App\Services\IncidentAcknowledgementService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ServiceIncidentResource extends Resource
{
    protected static ?string $model = ServiceIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'started_at';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('incidents.view') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('monitoring.service_incidents.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.service_incidents.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.service_incidents.resource.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.monitoring');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('monitored_service_id')
                    ->label(__('monitoring.service_incidents.fields.monitored_service_id.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.monitored_service_id.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.monitored_service_id.helper'))
                    ->relationship(name: 'monitoredService', titleAttribute: 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('started_at')
                    ->label(__('monitoring.service_incidents.fields.started_at.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.started_at.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.started_at.helper'))
                    ->required(),
                DateTimePicker::make('ended_at')
                    ->label(__('monitoring.service_incidents.fields.ended_at.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.ended_at.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.ended_at.helper')),
                TextInput::make('duration_minutes')
                    ->label(__('monitoring.service_incidents.fields.duration_minutes.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.duration_minutes.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.duration_minutes.helper'))
                    ->integer()
                    ->readOnly(),
                Select::make('incident_type')
                    ->label(__('monitoring.service_incidents.fields.incident_type.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.incident_type.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.incident_type.helper'))
                    ->options(static::incidentTypeOptions())
                    ->native(false),
                Select::make('severity')
                    ->label(__('monitoring.service_incidents.fields.severity.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.severity.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.severity.helper'))
                    ->options(static::severityOptions())
                    ->required()
                    ->default('medium')
                    ->native(false),
                Select::make('status')
                    ->label(__('monitoring.service_incidents.fields.status.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.status.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.status.helper'))
                    ->options(static::statusOptions())
                    ->required()
                    ->default('open')
                    ->native(false),
                Textarea::make('root_cause')
                    ->label(__('monitoring.service_incidents.fields.root_cause.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.root_cause.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.root_cause.helper'))
                    ->columnSpanFull(),
                Textarea::make('corrective_action')
                    ->label(__('monitoring.service_incidents.fields.corrective_action.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.corrective_action.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.corrective_action.helper'))
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('monitoring.service_incidents.fields.notes.label'))
                    ->placeholder(__('monitoring.service_incidents.fields.notes.placeholder'))
                    ->helperText(__('monitoring.service_incidents.fields.notes.helper'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.service_incidents.table.monitored_service.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('monitoring.service_incidents.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statusOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'danger',
                        'closed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('incident_type')
                    ->label(__('monitoring.service_incidents.table.incident_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::incidentTypeOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'down', 'timeout', 'connection_error' => 'danger',
                        'server_error' => 'warning',
                        'slow', 'keyword_missing', 'mixed' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('severity')
                    ->label(__('monitoring.service_incidents.table.severity'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::severityOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('started_at')
                    ->label(__('monitoring.service_incidents.table.started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label(__('monitoring.service_incidents.table.ended_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label(__('monitoring.service_incidents.table.duration_minutes'))
                    ->formatStateUsing(fn (int|string|null $state): string => $state === null ? '-' : trans_choice('monitoring.units.minutes', (int) $state, ['count' => number_format((int) $state)]))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('monitoring.service_incidents.table.updated_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('acknowledgedBy.name')->label('Acknowledged by'),
                TextColumn::make('acknowledged_at')->label('Acknowledged at')->dateTime(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle)
            ->emptyStateHeading(__('monitoring.empty_states.no_incidents'))
            ->filters([
                Filter::make('open_incidents')
                    ->label(__('monitoring.service_incidents.filters.open'))
                    ->query(fn (Builder $query): Builder => $query->where('status', 'open')),
                Filter::make('closed_incidents')
                    ->label(__('monitoring.service_incidents.filters.closed'))
                    ->query(fn (Builder $query): Builder => $query->where('status', 'closed')),
                SelectFilter::make('monitored_service_id')
                    ->label(__('monitoring.service_incidents.filters.service'))
                    ->relationship('monitoredService', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('incident_type')
                    ->label(__('monitoring.service_incidents.filters.incident_type'))
                    ->options(static::incidentTypeOptions()),
                SelectFilter::make('severity')
                    ->label(__('monitoring.service_incidents.filters.severity'))
                    ->options(static::severityOptions()),
                Filter::make('started_at')
                    ->label(__('monitoring.service_incidents.filters.started_at'))
                    ->schema([
                        DatePicker::make('started_from')
                            ->label(__('monitoring.service_incidents.filters.started_at_from')),
                        DatePicker::make('started_until')
                            ->label(__('monitoring.service_incidents.filters.started_at_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['started_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date),
                            )
                            ->when(
                                $data['started_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['started_from'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.service_incidents.filters.started_at_from').' '.Carbon::parse($data['started_from'])->toFormattedDateString())
                                ->removeField('started_from');
                        }

                        if ($data['started_until'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.service_incidents.filters.started_at_until').' '.Carbon::parse($data['started_until'])->toFormattedDateString())
                                ->removeField('started_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label('Acknowledge incident')
                    ->visible(fn (ServiceIncident $record): bool => $record->status === 'open' && $record->acknowledged_at === null && (auth()->user()?->can('incidents.acknowledge') ?? false))
                    ->action(function (ServiceIncident $record): void {
                        abort_unless(auth()->user()?->can('incidents.acknowledge'), 403);
                        app(IncidentAcknowledgementService::class)->acknowledge(auth()->user(), $record);
                    }),
                static::markClosedAction(),
                static::reopenAction(),
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

    public static function markClosedAction(): Action
    {
        return Action::make('mark_closed')
            ->label(__('monitoring.actions.mark_closed'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (ServiceIncident $record): bool => $record->status === 'open')
            ->action(function (ServiceIncident $record): void {
                $endedAt = now();

                $record->update([
                    'status' => 'closed',
                    'ended_at' => $endedAt,
                    'duration_minutes' => (int) $record->started_at->diffInMinutes($endedAt),
                ]);
            });
    }

    public static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('monitoring.actions.reopen'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (ServiceIncident $record): bool => $record->status === 'closed')
            ->action(fn (ServiceIncident $record): bool => $record->update([
                'status' => 'open',
                'ended_at' => null,
                'duration_minutes' => null,
            ]));
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
            'index' => ListServiceIncidents::route('/'),
            'create' => CreateServiceIncident::route('/create'),
            'edit' => EditServiceIncident::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'open' => __('monitoring.service_incidents.statuses.open'),
            'closed' => __('monitoring.service_incidents.statuses.closed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function incidentTypeOptions(): array
    {
        return [
            'down' => __('monitoring.service_incidents.incident_types.down'),
            'slow' => __('monitoring.service_incidents.incident_types.slow'),
            'server_error' => __('monitoring.service_incidents.incident_types.server_error'),
            'timeout' => __('monitoring.service_incidents.incident_types.timeout'),
            'connection_error' => __('monitoring.service_incidents.incident_types.connection_error'),
            'keyword_missing' => __('monitoring.service_incidents.incident_types.keyword_missing'),
            'mixed' => __('monitoring.service_incidents.incident_types.mixed'),
            'unknown' => __('monitoring.service_incidents.incident_types.unknown'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function severityOptions(): array
    {
        return [
            'low' => __('monitoring.service_incidents.severities.low'),
            'medium' => __('monitoring.service_incidents.severities.medium'),
            'high' => __('monitoring.service_incidents.severities.high'),
            'critical' => __('monitoring.service_incidents.severities.critical'),
        ];
    }
}
