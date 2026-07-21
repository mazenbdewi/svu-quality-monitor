<?php

namespace App\Filament\Resources\ReliabilityMetrics;

use App\Filament\Resources\ReliabilityMetrics\Pages\EditReliabilityMetric;
use App\Filament\Resources\ReliabilityMetrics\Pages\ListReliabilityMetrics;
use App\Filament\Resources\ReliabilityMetrics\Pages\ViewReliabilityMetric;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Services\ReliabilityMetricCalculator;
use App\Services\ResearchInterpretationService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReliabilityMetricResource extends Resource
{
    protected static ?string $model = ReliabilityMetric::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'period_start';

    public static function getModelLabel(): string
    {
        return __('monitoring.reliability_metrics.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.reliability_metrics.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.reliability_metrics.resource.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.analysis_reliability');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('monitored_service_id')
                    ->label(__('monitoring.reliability_metrics.fields.monitored_service_id.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.monitored_service_id.helper'))
                    ->relationship(name: 'monitoredService', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->disabled(),
                Select::make('period_type')
                    ->label(__('monitoring.reliability_metrics.fields.period_type.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.period_type.helper'))
                    ->options(static::periodTypeOptions())
                    ->native(false)
                    ->disabled(),
                DateTimePicker::make('period_start')
                    ->label(__('monitoring.reliability_metrics.fields.period_start.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.period_start.helper'))
                    ->disabled(),
                DateTimePicker::make('period_end')
                    ->label(__('monitoring.reliability_metrics.fields.period_end.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.period_end.helper'))
                    ->disabled(),
                TextInput::make('total_checks')
                    ->label(__('monitoring.reliability_metrics.fields.total_checks.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.total_checks.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('successful_checks')
                    ->label(__('monitoring.reliability_metrics.fields.successful_checks.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.successful_checks.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('failed_checks')
                    ->label(__('monitoring.reliability_metrics.fields.failed_checks.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.failed_checks.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('incidents_count')
                    ->label(__('monitoring.reliability_metrics.fields.incidents_count.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.incidents_count.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('uptime_minutes')
                    ->label(__('monitoring.reliability_metrics.fields.uptime_minutes.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.uptime_minutes.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('downtime_minutes')
                    ->label(__('monitoring.reliability_metrics.fields.downtime_minutes.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.downtime_minutes.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('availability_percent')
                    ->label(__('monitoring.reliability_metrics.fields.availability_percent.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.availability_percent.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('mtbf_minutes')
                    ->label(__('monitoring.reliability_metrics.fields.mtbf_minutes.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.mtbf_minutes.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('mttr_minutes')
                    ->label(__('monitoring.reliability_metrics.fields.mttr_minutes.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.mttr_minutes.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('failure_rate')
                    ->label(__('monitoring.reliability_metrics.fields.failure_rate.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.failure_rate.helper'))
                    ->numeric()
                    ->disabled(),
                DateTimePicker::make('calculated_at')
                    ->label(__('monitoring.reliability_metrics.fields.calculated_at.label'))
                    ->helperText(__('monitoring.reliability_metrics.fields.calculated_at.helper'))
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.reliability_metrics.table.monitored_service.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period_type')
                    ->label(__('monitoring.reliability_metrics.table.period_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::periodTypeOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'daily' => 'info',
                        'weekly' => 'warning',
                        'monthly' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('period_start')
                    ->label(__('monitoring.reliability_metrics.table.period_start'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('period_end')
                    ->label(__('monitoring.reliability_metrics.table.period_end'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total_checks')
                    ->label(__('monitoring.reliability_metrics.table.total_checks'))
                    ->sortable(),
                TextColumn::make('successful_checks')
                    ->label(__('monitoring.reliability_metrics.table.successful_checks'))
                    ->sortable(),
                TextColumn::make('failed_checks')
                    ->label(__('monitoring.reliability_metrics.table.failed_checks'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('incidents_count')
                    ->label(__('monitoring.reliability_metrics.table.incidents_count'))
                    ->badge()
                    ->color(fn (int|string|null $state): string => match (true) {
                        (int) $state === 0 => 'success',
                        (int) $state <= 2 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('availability_percent')
                    ->label(__('monitoring.reliability_metrics.table.availability_percent'))
                    ->badge()
                    ->formatStateUsing(fn (int|float|string|null $state): string => $state === null ? '' : number_format((float) $state, 4).'%')
                    ->color(fn (int|float|string|null $state): string => match (true) {
                        (float) $state >= 99 => 'success',
                        (float) $state >= 95 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('quality_level')
                    ->label(__('monitoring.interpretation.labels.quality_level'))
                    ->badge()
                    ->getStateUsing(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->availabilityLevel(
                        $record->availability_percent === null ? null : (float) $record->availability_percent,
                    ))
                    ->formatStateUsing(fn (string $state): string => __("monitoring.interpretation.levels.{$state}"))
                    ->color(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->availabilityColor(
                        $record->availability_percent === null ? null : (float) $record->availability_percent,
                    )),
                TextColumn::make('mtbf_minutes')
                    ->label(__('monitoring.reliability_metrics.table.mtbf_minutes'))
                    ->formatStateUsing(fn (int|float|string|null $state): string => $state === null ? __('monitoring.dashboard.empty.value') : trans_choice('monitoring.units.minutes', (int) round((float) $state), ['count' => number_format((float) $state, 2)]))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mttr_minutes')
                    ->label(__('monitoring.reliability_metrics.table.mttr_minutes'))
                    ->formatStateUsing(fn (int|float|string|null $state): string => $state === null ? __('monitoring.dashboard.empty.value') : trans_choice('monitoring.units.minutes', (int) round((float) $state), ['count' => number_format((float) $state, 2)]))
                    ->sortable(),
                TextColumn::make('failure_rate')
                    ->label(__('monitoring.reliability_metrics.table.failure_rate'))
                    ->numeric(decimalPlaces: 8)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('calculated_at')
                    ->label(__('monitoring.reliability_metrics.table.calculated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading(__('monitoring.empty_states.no_reliability_metrics'))
            ->filters([
                SelectFilter::make('monitored_service_id')
                    ->label(__('monitoring.reliability_metrics.filters.service'))
                    ->relationship('monitoredService', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('period_type')
                    ->label(__('monitoring.reliability_metrics.filters.period_type'))
                    ->options(static::periodTypeOptions()),
                Filter::make('period_start')
                    ->label(__('monitoring.reliability_metrics.filters.period_start'))
                    ->schema([
                        DatePicker::make('period_start_from')
                            ->label(__('monitoring.reliability_metrics.filters.period_start_from')),
                        DatePicker::make('period_start_until')
                            ->label(__('monitoring.reliability_metrics.filters.period_start_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['period_start_from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date),
                            )
                            ->when(
                                $data['period_start_until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['period_start_from'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.reliability_metrics.filters.period_start_from').' '.Carbon::parse($data['period_start_from'])->toFormattedDateString())
                                ->removeField('period_start_from');
                        }

                        if ($data['period_start_until'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.reliability_metrics.filters.period_start_until').' '.Carbon::parse($data['period_start_until'])->toFormattedDateString())
                                ->removeField('period_start_until');
                        }

                        return $indicators;
                    }),
                Filter::make('high_availability')
                    ->label(__('monitoring.reliability_metrics.filters.high_availability'))
                    ->query(fn (Builder $query): Builder => $query->where('availability_percent', '>=', 99)),
                Filter::make('low_availability')
                    ->label(__('monitoring.reliability_metrics.filters.low_availability'))
                    ->query(fn (Builder $query): Builder => $query->where('availability_percent', '<', 95)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('monitoring.actions.view'))
                    ->url(fn (ReliabilityMetric $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(__('monitoring.actions.edit')),
            ])
            ->recordUrl(fn (ReliabilityMetric $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        $cardClasses = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900';
        $valueClasses = 'text-base font-semibold leading-6 text-gray-950 dark:text-white';
        $formatNumber = fn (mixed $value, int $decimals = 2): string => $value === null ? __('monitoring.dashboard.empty.value') : number_format((float) $value, $decimals);
        $formatDateTime = function (mixed $value): string {
            if (! $value) {
                return __('monitoring.dashboard.empty.value');
            }

            $date = $value instanceof Carbon ? $value : Carbon::parse($value);
            $format = app()->getLocale() === 'ar' ? 'd-m-Y H:i' : 'Y-m-d H:i';

            return $date->format($format);
        };

        return $schema
            ->components([
                Section::make(__('monitoring.reliability_metrics.resource.model_label'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 4,
                        ])
                            ->schema([
                                TextEntry::make('monitoredService.name')
                                    ->label(__('monitoring.reliability_metrics.table.monitored_service.name'))
                                    ->placeholder(__('monitoring.dashboard.empty.value'))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('period_type')
                                    ->label(__('monitoring.reliability_metrics.table.period_type'))
                                    ->formatStateUsing(fn (?string $state): string => static::periodTypeOptions()[$state] ?? (string) $state)
                                    ->badge(),
                                TextEntry::make('period')
                                    ->label(__('monitoring.control_charts.summary.period'))
                                    ->state(fn (ReliabilityMetric $record): string => $formatDateTime($record->period_start).' - '.$formatDateTime($record->period_end))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('availability_percent')
                                    ->label(__('monitoring.reliability_metrics.table.availability_percent'))
                                    ->formatStateUsing(fn (mixed $state): string => $state === null ? __('monitoring.dashboard.empty.value') : number_format((float) $state, 4).'%')
                                    ->badge()
                                    ->color(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->availabilityColor(
                                        $record->availability_percent === null ? null : (float) $record->availability_percent,
                                    )),
                                TextEntry::make('incidents_count')
                                    ->label(__('monitoring.reliability_metrics.table.incidents_count'))
                                    ->formatStateUsing(fn (mixed $state): string => number_format((int) $state))
                                    ->badge()
                                    ->color(fn (mixed $state): string => (int) $state === 0 ? 'success' : 'warning'),
                                TextEntry::make('downtime_minutes')
                                    ->label(__('monitoring.reliability_metrics.table.downtime_minutes'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state, 0)),
                                TextEntry::make('mttr_minutes')
                                    ->label(__('monitoring.reliability_metrics.table.mttr_minutes'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state, 2)),
                                TextEntry::make('failure_rate')
                                    ->label(__('monitoring.reliability_metrics.table.failure_rate'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state, 8)),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make(__('monitoring.interpretation.sections.research_interpretation'))
                    ->schema([
                        TextEntry::make('interpretation_level')
                            ->label(__('monitoring.interpretation.labels.level'))
                            ->state(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->reliabilityFinding($record)['level'])
                            ->formatStateUsing(fn (string $state): string => __("monitoring.interpretation.levels.{$state}"))
                            ->badge()
                            ->color(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->reliabilityFinding($record)['color']),
                        TextEntry::make('interpretation_finding')
                            ->label(__('monitoring.interpretation.labels.finding'))
                            ->state(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->reliabilityFinding($record)['message'])
                            ->extraAttributes(['class' => 'text-sm leading-6 text-gray-600 dark:text-gray-300']),
                        TextEntry::make('interpretation_recommendation')
                            ->label(__('monitoring.interpretation.labels.recommendation'))
                            ->state(fn (ReliabilityMetric $record): string => app(ResearchInterpretationService::class)->reliabilityFinding($record)['recommendation'])
                            ->weight(FontWeight::SemiBold),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    public static function calculateTodayAction(): Action
    {
        return Action::make('calculate_today')
            ->label(__('monitoring.actions.calculate_today'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (): void {
                $calculator = app(ReliabilityMetricCalculator::class);
                $start = now()->startOfDay();
                $end = now()->endOfDay();

                MonitoredService::query()
                    ->where('is_active', true)
                    ->each(fn (MonitoredService $service): ReliabilityMetric => $calculator->calculateForService(
                        $service,
                        $start->copy(),
                        $end->copy(),
                        'daily',
                    ));

                Notification::make()
                    ->success()
                    ->title(__('monitoring.reliability_metrics.notifications.calculated'))
                    ->send();
            });
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
            'index' => ListReliabilityMetrics::route('/'),
            'view' => ViewReliabilityMetric::route('/{record}'),
            'edit' => EditReliabilityMetric::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function periodTypeOptions(): array
    {
        return [
            'daily' => __('monitoring.reliability_metrics.period_types.daily'),
            'weekly' => __('monitoring.reliability_metrics.period_types.weekly'),
            'monthly' => __('monitoring.reliability_metrics.period_types.monthly'),
        ];
    }
}
