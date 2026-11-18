<?php

namespace App\Filament\Resources\ControlChartBaselines;

use App\Exports\Baselines\PhaseOneBaselineExport;
use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlChartBaselines\Pages\ViewControlChartBaseline;
use App\Models\ControlChartBaseline;
use App\Services\Baselines\DeletePhaseOneBaseline;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\InstitutionTimezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;
use UnitEnum;

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
        return in_array($record->status, ['draft', 'rejected'], true) && static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        return (auth()->user()?->is_active && auth()->user()?->can(PhaseOneBaselineService::PERMISSION)) ?? false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.advanced_research');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('monitoredService.name')->label(__('baselines.service'))->searchable()->wrap(),
            TextColumn::make('chart_type')->label(__('baselines.chart'))
                ->formatStateUsing(fn (string $state): string => __('baselines.chart_types.'.$state))->wrap(),
            TextColumn::make('version')->label(__('baselines.version'))->alignCenter(),
            TextColumn::make('status')->label(__('baselines.status'))->badge()->alignCenter()
                ->formatStateUsing(fn (string $state): string => __('baselines.statuses.'.$state))
                ->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected', 'retired' => 'danger', 'reviewed' => 'info', default => 'warning',
                }),
            TextColumn::make('baseline_start')->label(__('baselines.list_start'))->alignCenter()->html()
                ->formatStateUsing(fn ($state): HtmlString => static::localDateTime($state)),
            TextColumn::make('baseline_end')->label(__('baselines.list_end'))->alignCenter()->html()
                ->formatStateUsing(fn ($state): HtmlString => static::localDateTime($state)),
        ])->recordActions([ViewAction::make(), static::deleteBaselineAction()])->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]));
    }

    private static function localDateTime(mixed $value): HtmlString
    {
        $local = Carbon::parse($value)->setTimezone(app(InstitutionTimezone::class)->name());

        return new HtmlString(e($local->format('Y-m-d')).'<br><span class="text-xs text-gray-500">'.e($local->format('H:i:s')).'</span>');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('monitoredService.name')->label(__('baselines.service')),
            TextEntry::make('chart_type')->label(__('baselines.chart'))
                ->formatStateUsing(fn (string $state): string => __('baselines.chart_types.'.$state)),
            TextEntry::make('version')->label(__('baselines.version')),
            TextEntry::make('status')->label(__('baselines.status'))
                ->formatStateUsing(fn (string $state): string => __('baselines.statuses.'.$state)),
            ViewEntry::make('phase_one_review')->hiddenLabel()->view('filament.baselines.review')->columnSpanFull(),
        ]);
    }

    public static function lifecycleActions(): array
    {
        return [
            static::deleteBaselineAction(true),
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

    public static function deleteBaselineAction(bool $redirect = false): Action
    {
        return Action::make('delete_draft')->label(__('baselines.delete'))->color('danger')->icon(Heroicon::OutlinedTrash)
            ->visible(fn (ControlChartBaseline $record): bool => static::canDelete($record))
            ->authorize(fn (ControlChartBaseline $record): bool => static::canDelete($record))
            ->requiresConfirmation()
            ->modalHeading(__('baselines.delete'))
            ->modalDescription(fn (ControlChartBaseline $record): string => __('baselines.delete_confirmation', [
                'count' => app(DeletePhaseOneBaseline::class)->count($record),
            ]))
            ->modalSubmitActionLabel(__('baselines.delete'))
            ->action(function (ControlChartBaseline $record) use ($redirect) {
                try {
                    $count = app(DeletePhaseOneBaseline::class)->delete($record, auth()->user());
                } catch (AuthorizationException $e) {
                    throw $e;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                    return null;
                }
                Notification::make()->success()->title(__('baselines.deleted', ['count' => $count]))->send();

                return $redirect ? redirect(static::getUrl('index')) : null;
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListControlChartBaselines::route('/'), 'view' => ViewControlChartBaseline::route('/{record}')];
    }
}
