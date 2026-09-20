<?php

namespace App\Filament\Resources\ControlChartBaselines;

use App\Exports\Baselines\PhaseOneBaselineExport;
use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlChartBaselines\Pages\ViewControlChartBaseline;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Services\Baselines\PhaseOneBaselineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class ControlChartBaselineResource extends Resource
{
    protected static ?string $model = ControlChartBaseline::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 12;

    public static function getModelLabel(): string
    {
        return __('baselines.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('baselines.title');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('control_charts.view') ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        return (auth()->user()?->is_active && auth()->user()?->can(PhaseOneBaselineService::PERMISSION)) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('monitoredService.name')->label(__('baselines.service')),
            TextColumn::make('chart_type')->label(__('baselines.chart')),
            TextColumn::make('version')->label(__('baselines.version')),
            TextColumn::make('status')->label(__('baselines.status'))->badge(),
            TextColumn::make('baseline_start')->label(__('baselines.start'))->dateTime(),
            TextColumn::make('baseline_end')->label(__('baselines.end'))->dateTime(),
            TextColumn::make('review_context.sufficiency')->label(__('baselines.sufficiency')),
        ])->recordActions([ViewAction::make()])->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('monitoredService.name')->label(__('baselines.service')),
            TextEntry::make('chart_type')->label(__('baselines.chart')),
            TextEntry::make('version')->label(__('baselines.version')),
            TextEntry::make('status')->label(__('baselines.status')),
            ViewEntry::make('phase_one_review')->hiddenLabel()->view('filament.baselines.review')->columnSpanFull(),
        ]);
    }

    public static function generateAction(): Action
    {
        return Action::make('create_phase_one_baseline')->label(__('baselines.create'))->visible(fn () => static::canManage())
            ->schema([
                Select::make('service_id')->label(__('baselines.service'))->options(fn () => MonitoredService::orderBy('name')->pluck('name', 'id'))->required()->searchable(),
                Select::make('chart_type')->label(__('baselines.chart'))->options(['i_chart' => 'I', 'mr_chart' => 'MR', 'p_chart' => 'P'])->required()->default('i_chart'),
                DateTimePicker::make('baseline_start')->label(__('baselines.start'))->required()->seconds(false),
                DateTimePicker::make('baseline_end')->label(__('baselines.end'))->required()->after('baseline_start')->seconds(false),
                Select::make('analysis_timezone')->label(__('baselines.timezone'))->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))->searchable()->default(config('monitoring.spc.analysis_timezone'))->required(),
                Select::make('aggregation_interval')->label(__('baselines.aggregation'))->options(['hourly' => 'Hourly', 'daily' => 'Daily'])->default('hourly')->required(),
            ])->action(function (array $data) {
                $baseline = app(PhaseOneBaselineService::class)->create(MonitoredService::findOrFail($data['service_id']), $data['chart_type'], $data['baseline_start'], $data['baseline_end'], $data['analysis_timezone'], auth()->user(), $data['aggregation_interval']);

                return redirect(static::getUrl('view', ['record' => $baseline]));
            });
    }

    public static function lifecycleActions(): array
    {
        return [
            Action::make('review_baseline')->label(__('baselines.review'))->visible(fn ($record) => static::canManage() && $record->status === 'draft')
                ->schema([Textarea::make('notes')->label(__('baselines.review_notes'))->required()->maxLength(10000), Toggle::make('acknowledge')->label(__('baselines.acknowledge'))->accepted()->required()])
                ->action(fn ($record, array $data) => app(PhaseOneBaselineService::class)->review($record, auth()->user(), $data['notes'], (bool) ($data['acknowledge'] ?? false))),
            ...array_map(fn ($event) => Action::make($event.'_baseline')->label(__('baselines.'.$event))
                ->visible(fn ($record) => static::canManage() && match ($event) {
                    'approve' => $record->status === 'reviewed', 'retire' => $record->status === 'approved', default => in_array($record->status, ['draft', 'reviewed'], true)
                })
                ->schema([Textarea::make('reason')->label(__('baselines.reason'))->required()->maxLength(10000)])
                ->action(fn ($record, array $data) => app(PhaseOneBaselineService::class)->{$event}($record, auth()->user(), $data['reason'])), ['approve', 'retire', 'reject']),
            Action::make('export_baseline')->label(__('baselines.export'))->visible(fn () => static::canViewAny())
                ->action(function ($record) {
                    Gate::authorize('control_charts.view');

                    return Excel::download(new PhaseOneBaselineExport($record), 'baseline-'.$record->id.'-v'.$record->version.'.xlsx');
                }),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListControlChartBaselines::route('/'), 'view' => ViewControlChartBaseline::route('/{record}')];
    }
}
