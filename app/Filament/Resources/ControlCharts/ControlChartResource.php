<?php

namespace App\Filament\Resources\ControlCharts;

use App\Filament\Resources\ControlCharts\Pages\EditControlChart;
use App\Filament\Resources\ControlCharts\Pages\ListControlCharts;
use App\Filament\Resources\ControlCharts\Pages\ViewControlChart;
use App\Filament\Resources\ControlCharts\RelationManagers\PointsRelationManager;
use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Services\ControlChartCalculator;
use App\Services\ResearchInterpretationService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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

class ControlChartResource extends Resource
{
    protected static ?string $model = ControlChart::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'chart_type';

    public static function getModelLabel(): string
    {
        return __('monitoring.control_charts.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('monitoring.control_charts.resource.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('monitoring.control_charts.resource.navigation_label');
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
                    ->label(__('monitoring.control_charts.fields.monitored_service_id.label'))
                    ->helperText(__('monitoring.control_charts.fields.monitored_service_id.helper'))
                    ->relationship(name: 'monitoredService', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->disabled(),
                Select::make('chart_type')
                    ->label(__('monitoring.control_charts.fields.chart_type.label'))
                    ->helperText(__('monitoring.control_charts.fields.chart_type.helper'))
                    ->options(static::chartTypeOptions())
                    ->native(false)
                    ->disabled(),
                Select::make('metric_name')
                    ->label(__('monitoring.control_charts.fields.metric_name.label'))
                    ->helperText(__('monitoring.control_charts.fields.metric_name.helper'))
                    ->options(static::metricNameOptions())
                    ->native(false)
                    ->disabled(),
                Select::make('period_type')
                    ->label(__('monitoring.control_charts.fields.period_type.label'))
                    ->helperText(__('monitoring.control_charts.fields.period_type.helper'))
                    ->options(static::periodTypeOptions())
                    ->native(false)
                    ->disabled(),
                DateTimePicker::make('period_start')
                    ->label(__('monitoring.control_charts.fields.period_start.label'))
                    ->helperText(__('monitoring.control_charts.fields.period_start.helper'))
                    ->disabled(),
                DateTimePicker::make('period_end')
                    ->label(__('monitoring.control_charts.fields.period_end.label'))
                    ->helperText(__('monitoring.control_charts.fields.period_end.helper'))
                    ->disabled(),
                TextInput::make('center_line')
                    ->label(__('monitoring.control_charts.fields.center_line.label'))
                    ->helperText(__('monitoring.control_charts.fields.center_line.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('ucl')
                    ->label(__('monitoring.control_charts.fields.ucl.label'))
                    ->helperText(__('monitoring.control_charts.fields.ucl.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('lcl')
                    ->label(__('monitoring.control_charts.fields.lcl.label'))
                    ->helperText(__('monitoring.control_charts.fields.lcl.helper'))
                    ->numeric()
                    ->disabled(),
                TextInput::make('points_count')
                    ->label(__('monitoring.control_charts.fields.points_count.label'))
                    ->helperText(__('monitoring.control_charts.fields.points_count.helper'))
                    ->integer()
                    ->disabled(),
                TextInput::make('out_of_control_count')
                    ->label(__('monitoring.control_charts.fields.out_of_control_count.label'))
                    ->helperText(__('monitoring.control_charts.fields.out_of_control_count.helper'))
                    ->integer()
                    ->disabled(),
                DateTimePicker::make('calculated_at')
                    ->label(__('monitoring.control_charts.fields.calculated_at.label'))
                    ->helperText(__('monitoring.control_charts.fields.calculated_at.helper'))
                    ->disabled(),
                Textarea::make('notes')
                    ->label(__('monitoring.control_charts.fields.notes.label'))
                    ->helperText(__('monitoring.control_charts.fields.notes.helper'))
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $cardClasses = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900';
        $valueClasses = 'text-base font-semibold leading-6 text-gray-950 dark:text-white';
        $formatNumber = fn (mixed $value): string => $value === null ? __('monitoring.dashboard.empty.value') : number_format((float) $value, 2);
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
                Section::make(__('monitoring.control_charts.sections.summary'))
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 4,
                        ])
                            ->schema([
                                TextEntry::make('monitoredService.name')
                                    ->label(__('monitoring.control_charts.summary.service'))
                                    ->placeholder(__('monitoring.dashboard.empty.value'))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('chart_type')
                                    ->label(__('monitoring.control_charts.summary.chart_type'))
                                    ->formatStateUsing(fn (?string $state): string => static::chartTypeOptions()[$state] ?? (string) $state)
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses.' border-blue-200 dark:border-blue-800']),
                                TextEntry::make('metric_name')
                                    ->label(__('monitoring.control_charts.summary.metric_name'))
                                    ->formatStateUsing(fn (?string $state): string => static::metricNameOptions()[$state] ?? (string) $state)
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses.' border-indigo-200 dark:border-indigo-800']),
                                TextEntry::make('period')
                                    ->label(__('monitoring.control_charts.summary.period'))
                                    ->state(fn (ControlChart $record): string => $formatDateTime($record->period_start).' - '.$formatDateTime($record->period_end))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('center_line')
                                    ->label(__('monitoring.control_charts.summary.center_line'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses, 'dir' => 'ltr'])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses.' border-blue-200 dark:border-blue-800']),
                                TextEntry::make('ucl')
                                    ->label(__('monitoring.control_charts.summary.ucl'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses, 'dir' => 'ltr'])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses.' border-orange-200 dark:border-orange-800']),
                                TextEntry::make('lcl')
                                    ->label(__('monitoring.control_charts.summary.lcl'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatNumber($state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses, 'dir' => 'ltr'])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses.' border-sky-200 dark:border-sky-800']),
                                TextEntry::make('points_count')
                                    ->label(__('monitoring.control_charts.summary.points_count'))
                                    ->formatStateUsing(fn (mixed $state): string => number_format((int) $state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses, 'dir' => 'ltr'])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('out_of_control_count')
                                    ->label(__('monitoring.control_charts.summary.out_of_control_points'))
                                    ->badge()
                                    ->color(fn (mixed $state): string => (int) $state > 0 ? 'danger' : 'success')
                                    ->formatStateUsing(fn (mixed $state): string => number_format((int) $state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses, 'dir' => 'ltr'])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('calculated_at')
                                    ->label(__('monitoring.control_charts.summary.calculated_at'))
                                    ->formatStateUsing(fn (mixed $state): string => $formatDateTime($state))
                                    ->weight(FontWeight::SemiBold)
                                    ->extraAttributes(['class' => $valueClasses])
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                                TextEntry::make('process_status')
                                    ->label(__('monitoring.control_charts.summary.process_status'))
                                    ->state(fn (ControlChart $record): string => (int) $record->out_of_control_count > 0 ? 'out_of_control' : 'normal')
                                    ->formatStateUsing(fn (string $state): string => __("monitoring.control_charts.statuses.{$state}"))
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'normal' ? 'success' : 'danger')
                                    ->weight(FontWeight::SemiBold)
                                    ->extraEntryWrapperAttributes(['class' => $cardClasses]),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make(__('monitoring.control_charts.graph.title'))
                    ->schema([
                        ViewEntry::make('control_chart_graph')
                            ->hiddenLabel()
                            ->view('filament.infolists.control-chart-graph')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make(__('monitoring.interpretation.sections.control_chart_interpretation'))
                    ->schema([
                        TextEntry::make('control_chart_interpretation_level')
                            ->label(__('monitoring.interpretation.labels.level'))
                            ->state(fn (ControlChart $record): string => app(ResearchInterpretationService::class)->controlChartFinding($record)['level'])
                            ->formatStateUsing(fn (string $state): string => __("monitoring.interpretation.levels.{$state}"))
                            ->badge()
                            ->color(fn (ControlChart $record): string => app(ResearchInterpretationService::class)->controlChartFinding($record)['color']),
                        TextEntry::make('control_chart_out_of_control_points')
                            ->label(__('monitoring.interpretation.labels.out_of_control_points'))
                            ->state(fn (ControlChart $record): string => number_format((int) $record->out_of_control_count))
                            ->badge()
                            ->color(fn (ControlChart $record): string => app(ResearchInterpretationService::class)->controlChartFinding($record)['color']),
                        TextEntry::make('control_chart_interpretation_finding')
                            ->label(__('monitoring.interpretation.labels.finding'))
                            ->state(fn (ControlChart $record): string => app(ResearchInterpretationService::class)->controlChartFinding($record)['message'])
                            ->extraAttributes(['class' => 'text-sm leading-6 text-gray-600 dark:text-gray-300']),
                        TextEntry::make('control_chart_interpretation_recommendation')
                            ->label(__('monitoring.interpretation.labels.recommendation'))
                            ->state(fn (ControlChart $record): string => app(ResearchInterpretationService::class)->controlChartFinding($record)['recommendation'])
                            ->weight(FontWeight::SemiBold),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PointsRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('calculated_at', 'desc')
            ->columns([
                TextColumn::make('monitoredService.name')
                    ->label(__('monitoring.control_charts.table.monitored_service.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('chart_type')
                    ->label(__('monitoring.control_charts.table.chart_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::chartTypeOptions()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'i_chart', 'mr_chart' => 'info',
                        'p_chart', 'u_chart' => 'warning',
                        'c_chart' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('metric_name')
                    ->label(__('monitoring.control_charts.table.metric_name'))
                    ->formatStateUsing(fn (?string $state): string => static::metricNameOptions()[$state] ?? (string) $state)
                    ->searchable(),
                TextColumn::make('period_type')
                    ->label(__('monitoring.control_charts.table.period_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::periodTypeOptions()[$state] ?? (string) $state),
                TextColumn::make('period_start')
                    ->label(__('monitoring.control_charts.table.period_start'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('period_end')
                    ->label(__('monitoring.control_charts.table.period_end'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('center_line')
                    ->label(__('monitoring.control_charts.table.center_line'))
                    ->numeric(decimalPlaces: 4)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ucl')
                    ->label(__('monitoring.control_charts.table.ucl'))
                    ->numeric(decimalPlaces: 4)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lcl')
                    ->label(__('monitoring.control_charts.table.lcl'))
                    ->numeric(decimalPlaces: 4)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('points_count')
                    ->label(__('monitoring.control_charts.table.points_count'))
                    ->sortable(),
                TextColumn::make('out_of_control_count')
                    ->label(__('monitoring.control_charts.table.out_of_control_count'))
                    ->badge()
                    ->color(fn (int|string|null $state): string => (int) $state > 0 ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('calculated_at')
                    ->label(__('monitoring.control_charts.table.calculated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPresentationChartLine)
            ->emptyStateHeading(__('monitoring.empty_states.no_control_charts'))
            ->filters([
                SelectFilter::make('monitored_service_id')
                    ->label(__('monitoring.control_charts.filters.service'))
                    ->relationship('monitoredService', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('chart_type')
                    ->label(__('monitoring.control_charts.filters.chart_type'))
                    ->options(static::chartTypeOptions()),
                SelectFilter::make('period_type')
                    ->label(__('monitoring.control_charts.filters.period_type'))
                    ->options(static::periodTypeOptions()),
                Filter::make('out_of_control')
                    ->label(__('monitoring.control_charts.filters.out_of_control'))
                    ->query(fn (Builder $query): Builder => $query->where('out_of_control_count', '>', 0)),
                Filter::make('period_start')
                    ->label(__('monitoring.control_charts.filters.period_start'))
                    ->schema([
                        DatePicker::make('period_start_from')
                            ->label(__('monitoring.control_charts.filters.period_start_from')),
                        DatePicker::make('period_start_until')
                            ->label(__('monitoring.control_charts.filters.period_start_until')),
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
                            $indicators[] = Indicator::make(__('monitoring.control_charts.filters.period_start_from').' '.Carbon::parse($data['period_start_from'])->toFormattedDateString())
                                ->removeField('period_start_from');
                        }

                        if ($data['period_start_until'] ?? null) {
                            $indicators[] = Indicator::make(__('monitoring.control_charts.filters.period_start_until').' '.Carbon::parse($data['period_start_until'])->toFormattedDateString())
                                ->removeField('period_start_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('monitoring.actions.view_control_chart'))
                    ->url(fn (ControlChart $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->label(__('monitoring.actions.edit')),
            ])
            ->recordUrl(fn (ControlChart $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function calculateControlChartsAction(): Action
    {
        return Action::make('calculate_control_charts')
            ->label(__('monitoring.actions.calculate_control_charts'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                Select::make('monitored_service_id')
                    ->label(__('monitoring.control_charts.actions.service'))
                    ->options(fn (): array => MonitoredService::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
                Select::make('chart_type')
                    ->label(__('monitoring.control_charts.actions.chart_type'))
                    ->options(static::chartTypeOptions())
                    ->native(false),
                DatePicker::make('date')
                    ->label(__('monitoring.control_charts.actions.date'))
                    ->default(now()),
                Select::make('bucket')
                    ->label(__('monitoring.control_charts.actions.bucket'))
                    ->options(static::bucketOptions())
                    ->default('hourly')
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $calculator = app(ControlChartCalculator::class);
                $date = isset($data['date']) && $data['date']
                    ? Carbon::parse($data['date'])
                    : now();
                $start = $date->copy()->startOfDay();
                $end = $date->copy()->endOfDay();
                $chartTypes = $data['chart_type']
                    ? [(string) $data['chart_type']]
                    : ControlChartCalculator::CHART_TYPES;

                $query = MonitoredService::query();

                if ($data['monitored_service_id'] ?? null) {
                    $query->whereKey((int) $data['monitored_service_id']);
                } else {
                    $query->where('is_active', true);
                }

                $query->each(function (MonitoredService $service) use ($calculator, $start, $end, $chartTypes, $data): void {
                    foreach ($chartTypes as $chartType) {
                        $calculator->calculate(
                            $service,
                            $chartType,
                            $start->copy(),
                            $end->copy(),
                            'daily',
                            (string) ($data['bucket'] ?? 'hourly'),
                        );
                    }
                });

                Notification::make()
                    ->success()
                    ->title(__('monitoring.control_charts.notifications.calculated'))
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListControlCharts::route('/'),
            'view' => ViewControlChart::route('/{record}'),
            'edit' => EditControlChart::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function chartTypeOptions(): array
    {
        return [
            'i_chart' => __('monitoring.control_charts.chart_types.i_chart'),
            'mr_chart' => __('monitoring.control_charts.chart_types.mr_chart'),
            'p_chart' => __('monitoring.control_charts.chart_types.p_chart'),
            'c_chart' => __('monitoring.control_charts.chart_types.c_chart'),
            'u_chart' => __('monitoring.control_charts.chart_types.u_chart'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function periodTypeOptions(): array
    {
        return [
            'daily' => __('monitoring.control_charts.period_types.daily'),
            'weekly' => __('monitoring.control_charts.period_types.weekly'),
            'monthly' => __('monitoring.control_charts.period_types.monthly'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function metricNameOptions(): array
    {
        return [
            'response_time_ms' => __('monitoring.metrics.response_time_ms'),
            'failure_proportion' => __('monitoring.metrics.failure_proportion'),
            'failed_checks_count' => __('monitoring.metrics.failed_checks_count'),
            'failures_per_check' => __('monitoring.metrics.failures_per_check'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function bucketOptions(): array
    {
        return [
            'hourly' => __('monitoring.control_charts.buckets.hourly'),
            'daily' => __('monitoring.control_charts.buckets.daily'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function signalTypeOptions(): array
    {
        return [
            'above_ucl' => __('monitoring.control_charts.signal_types.above_ucl'),
            'below_lcl' => __('monitoring.control_charts.signal_types.below_lcl'),
        ];
    }
}
