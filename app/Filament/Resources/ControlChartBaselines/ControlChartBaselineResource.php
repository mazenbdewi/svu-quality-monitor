<?php

namespace App\Filament\Resources\ControlChartBaselines;

use App\Exports\Baselines\PhaseOneBaselineExport;
use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlChartBaselines\Pages\ViewControlChartBaseline;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use App\Services\InstitutionTimezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        return $table->defaultGroup('monitoredService.name')->defaultSort('id', 'desc')->columns([
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
        return Action::make('create_phase_one_baseline')->label(__('baseline_creation.create'))->visible(fn () => static::canManage())
            ->modalSubmitActionLabel(__('baseline_creation.create'))
            ->modalSubmitAction(fn (Action $action, $livewire) => $action->disabled(fn () => ! $livewire->canSubmitBaseline()))
            ->beforeFormValidated(fn ($livewire) => $livewire->guardBaselineAvailability())
            ->schema([
                View::make('filament.baselines.creation-help'),
                Select::make('service_id')->label(__('baselines.service'))->options(fn () => MonitoredService::orderBy('name')->pluck('name', 'id'))->required()->searchable()->live()->rules(['exists:monitored_services,id'])
                    ->afterStateUpdated(fn (Get $get, Set $set, $livewire) => $livewire->baselineServiceChanged($get, $set)),
                Text::make(fn (Get $get, $livewire) => $livewire->baselineRangeMessage($get)),
                Text::make(fn () => __('baseline_creation.period_help', ['timezone' => app(InstitutionTimezone::class)->name()])),
                DateTimePicker::make('baseline_start')->label(__('baselines.start'))->required()->seconds(true)->live(debounce: 400)
                    ->afterStateUpdated(fn (Set $set, $livewire) => $livewire->baselinePeriodChanged($set)),
                DateTimePicker::make('baseline_end')->label(__('baselines.end'))->required()->after('baseline_start')->seconds(true)->live(debounce: 400)
                    ->afterStateUpdated(fn (Set $set, $livewire) => $livewire->baselinePeriodChanged($set)),
                DateTimePicker::make('data_cutoff')->label(__('baseline_creation.cutoff'))->seconds(true)->live(debounce: 400)
                    ->afterStateUpdated(fn (Set $set, $livewire) => $livewire->baselinePeriodChanged($set)),
                Select::make('analysis_timezone')->label(__('baselines.timezone'))->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))->searchable()->default(fn () => app(InstitutionTimezone::class)->name())->disabled()->dehydrated()->required(),
                Text::make(fn (Get $get, $livewire) => $livewire->baselineAvailability($get)['message']),
                CheckboxList::make('metrics')->label(__('baseline_creation.metrics'))->options([
                    'latency' => __('baseline_creation.latency'), 'problematic' => __('baseline_creation.problematic'),
                ])
                    ->descriptions(fn (Get $get, $livewire) => collect(['latency', 'problematic'])->mapWithKeys(function ($metric) use ($get, $livewire) {
                        $count = $livewire->baselineAvailability($get)[$metric];

                        return [$metric => $count > 0 ? __('baseline_creation.'.$metric.'_count', ['count' => $count]) : __('baseline_creation.'.$metric.'_unavailable')];
                    })->all())
                    ->default([])->required()->minItems(1)->live()
                    ->disableOptionWhen(fn (string $value, Get $get, $livewire) => ($livewire->baselineAvailability($get)[$value] ?? 0) === 0),
                Select::make('aggregation_interval')->label(__('baselines.aggregation'))->options(['hourly' => __('baseline_creation.hourly'), 'daily' => __('baseline_creation.daily')])->default('hourly')->required()
                    ->visible(fn (Get $get) => in_array('problematic', $get('metrics') ?? [], true)),
            ])->action(function (array $data, $livewire) {
                abort_unless(static::canManage(), 403);
                try {
                    $baselines = app(PhaseOneBaselineSubmission::class)->create($data, app(InstitutionTimezone::class)->name(), auth()->user());
                } catch (AuthorizationException $e) {
                    throw $e;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(__('baseline_creation.failed_title'))->body(collect($e->errors())->flatten()->implode(' '))->send();
                    $prefix = $livewire->baselineErrorPath();
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [$prefix.'.'.$field => $messages])->all());
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('baseline_creation.failed_title'))->body(__('baseline_creation.failed'))->persistent()->send();

                    return;
                }
                Notification::make()->success()->title(__('baseline_creation.created', ['count' => count($baselines)]))->send();
                if (collect($baselines)->contains(fn ($row) => $row->review_context['sufficiency'] !== 'analyzable')) {
                    Notification::make()->warning()->title(__('baseline_creation.limited'))->send();
                }

                return redirect(static::getUrl('index'));
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
