<?php

namespace App\Filament\Resources\ServiceChecks;

use App\Filament\Resources\ServiceChecks\Pages\CreateServiceCheck;
use App\Filament\Resources\ServiceChecks\Pages\EditServiceCheck;
use App\Filament\Resources\ServiceChecks\Pages\ListServiceChecks;
use App\Models\ServiceCheck;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class ServiceCheckResource extends Resource
{
    protected static ?string $model = ServiceCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'checked_at';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('services.view') ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('services.view') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('monitoring.service_checks.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.service_checks.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.service_checks.resource.navigation_label');
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
                    ->label(__('monitoring.service_checks.fields.monitored_service_id.label'))
                    ->helperText(__('monitoring.service_checks.fields.monitored_service_id.helper'))
                    ->relationship(name: 'monitoredService', titleAttribute: 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('checked_at')
                    ->label(__('monitoring.service_checks.fields.checked_at.label'))
                    ->helperText(__('monitoring.service_checks.fields.checked_at.helper'))
                    ->required(),
                TextInput::make('status_code')
                    ->label(__('monitoring.service_checks.fields.status_code.label'))
                    ->placeholder(__('monitoring.service_checks.fields.status_code.placeholder'))
                    ->helperText(__('monitoring.service_checks.fields.status_code.helper'))
                    ->integer()
                    ->minValue(100)
                    ->maxValue(599),
                TextInput::make('response_time_ms')
                    ->label(__('monitoring.service_checks.fields.response_time_ms.label'))
                    ->placeholder(__('monitoring.service_checks.fields.response_time_ms.placeholder'))
                    ->helperText(__('monitoring.service_checks.fields.response_time_ms.helper'))
                    ->integer()
                    ->minValue(0),
                Toggle::make('is_success')
                    ->label(__('monitoring.service_checks.fields.is_success.label'))
                    ->helperText(__('monitoring.service_checks.fields.is_success.helper')),
                Toggle::make('is_slow')
                    ->label(__('monitoring.service_checks.fields.is_slow.label'))
                    ->helperText(__('monitoring.service_checks.fields.is_slow.helper')),
                TextInput::make('error_type')
                    ->label(__('monitoring.service_checks.fields.error_type.label'))
                    ->placeholder(__('monitoring.service_checks.fields.error_type.placeholder'))
                    ->helperText(__('monitoring.service_checks.fields.error_type.helper'))
                    ->maxLength(255),
                Toggle::make('expected_keyword_found')
                    ->label(__('monitoring.service_checks.fields.expected_keyword_found.label'))
                    ->helperText(__('monitoring.service_checks.fields.expected_keyword_found.helper'))
                    ->nullable(),
                Textarea::make('error_message')
                    ->label(__('monitoring.service_checks.fields.error_message.label'))
                    ->placeholder(__('monitoring.service_checks.fields.error_message.placeholder'))
                    ->helperText(__('monitoring.service_checks.fields.error_message.helper'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.service_checks.table.monitored_service.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('checked_at')
                    ->label(__('monitoring.service_checks.table.checked_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status_code')
                    ->label(__('monitoring.service_checks.table.status_code'))
                    ->sortable(),
                TextColumn::make('response_time_ms')
                    ->label(__('monitoring.service_checks.table.response_time_ms'))
                    ->formatStateUsing(fn (int|string|null $state): string => $state === null ? '-' : number_format((int) $state).' ms')
                    ->sortable(),
                TextColumn::make('is_success')
                    ->label(__('monitoring.service_checks.table.is_success'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? __('monitoring.booleans.yes') : __('monitoring.booleans.no'))
                    ->color(fn (?bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('is_slow')
                    ->label(__('monitoring.service_checks.table.is_slow'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? __('monitoring.booleans.yes') : __('monitoring.booleans.no'))
                    ->color(fn (?bool $state): string => $state ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('is_during_maintenance')
                    ->label(__('monitoring.report_columns.is_during_maintenance'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? __('monitoring.booleans.yes') : __('monitoring.booleans.no'))
                    ->color(fn (?bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('error_type')
                    ->label(__('monitoring.service_checks.table.error_type'))
                    ->formatStateUsing(function (?string $state): string {
                        if (! $state) {
                            return '-';
                        }

                        $translationKey = "monitoring.service_status.problem_types.{$state}";
                        $translation = __($translationKey);

                        return $translation === $translationKey ? $state : $translation;
                    })
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expected_keyword_found')
                    ->label(__('monitoring.service_checks.table.expected_keyword_found'))
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state === null ? '-' : ($state ? __('monitoring.booleans.yes') : __('monitoring.booleans.no')))
                    ->color(fn (?bool $state): string => match ($state) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label(__('monitoring.service_checks.table.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(__('monitoring.empty_states.no_checks'))
            ->filters([
                Filter::make('successful_checks')
                    ->label(__('monitoring.service_checks.filters.successful'))
                    ->query(fn (Builder $query): Builder => $query->where('is_success', true)),
                Filter::make('failed_checks')
                    ->label(__('monitoring.service_checks.filters.failed'))
                    ->query(fn (Builder $query): Builder => $query->where('is_success', false)),
                Filter::make('slow_checks')
                    ->label(__('monitoring.service_checks.filters.slow'))
                    ->query(fn (Builder $query): Builder => $query->where('is_slow', true)),
                SelectFilter::make('monitored_service_id')
                    ->label(__('monitoring.service_checks.filters.service'))
                    ->relationship('monitoredService', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('checked_at')
                    ->label(__('monitoring.service_checks.filters.checked_at'))
                    ->schema([
                        DatePicker::make('checked_from')
                            ->label(__('monitoring.service_checks.filters.checked_at_from')),
                        DatePicker::make('checked_until')
                            ->label(__('monitoring.service_checks.filters.checked_at_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['checked_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '>=', $date),
                            )
                            ->when(
                                $data['checked_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['checked_from'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.service_checks.filters.checked_at_from').' '.Carbon::parse($data['checked_from'])->toFormattedDateString())
                                ->removeField('checked_from');
                        }

                        if ($data['checked_until'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.service_checks.filters.checked_at_until').' '.Carbon::parse($data['checked_until'])->toFormattedDateString())
                                ->removeField('checked_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
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
            'index' => ListServiceChecks::route('/'),
            'create' => CreateServiceCheck::route('/create'),
            'edit' => EditServiceCheck::route('/{record}/edit'),
        ];
    }
}
