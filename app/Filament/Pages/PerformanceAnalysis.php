<?php

namespace App\Filament\Pages;

use App\Models\MonitoredService;
use App\Models\SloProposal;
use App\Services\ControlChartCalculator;
use App\Services\InstitutionTimezone;
use App\Services\MonitoringCoverageCalculator;
use App\Services\ServiceQualityOverview;
use App\Services\SloProposalService;
use App\Services\SpcAnalysisWindow;
use App\Services\SpcResearchData;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

class PerformanceAnalysis extends Page
{
    protected static ?string $slug = 'performance-analysis';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.performance-analysis';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    public ?int $serviceId = null;

    public string $window = '30';

    public string $customStart = '';

    public string $customEnd = '';

    /** @var array<string, array<string, mixed>> */
    public array $charts = [];

    /** Display points travel as one scalar to avoid deep reactive proxies for large windows. */
    public string $chartPayload = '{}';

    /** Loaded only on explicit request; kept in Livewire state to avoid repeat percentile queries. */
    public array $qualityOverview = [];

    public bool $qualityOverviewLoaded = false;

    public bool $qualityOverviewError = false;

    /** @var array<string, mixed>|null */
    private ?array $summaryCache = null;

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_active && auth()->user()?->can('control_charts.view'));
    }

    public static function getNavigationLabel(): string
    {
        return __('performance_analysis.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.analysis_reliability');
    }

    public function getTitle(): string
    {
        return __('performance_analysis.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('')
            ->columns(['default' => 1, 'md' => 2])
            ->components([
                Select::make('serviceId')
                    ->label(__('performance_analysis.service'))
                    ->placeholder('—')
                    ->options(fn (): array => MonitoredService::orderBy('name')->get()
                        ->mapWithKeys(fn (MonitoredService $service): array => [
                            $service->id => $service->name.' · '.strtoupper($service->check_type->value),
                        ])->all())
                    ->searchable()
                    ->native(false)
                    ->wrapOptionLabels()
                    ->live(),
                Select::make('window')
                    ->label(__('performance_analysis.window'))
                    ->options(collect(['7', '15', '30', '60', '90'])
                        ->mapWithKeys(fn (string $days): array => [
                            $days => trans_choice('performance_analysis.days', (int) $days, ['count' => $days]),
                        ])->put('custom', __('performance_analysis.custom'))->all())
                    ->native(false)
                    ->live(),
            ]);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $requested = request()->integer('service');
        $this->serviceId = $requested && MonitoredService::whereKey($requested)->exists() ? $requested : null;
        $this->form->fill(['serviceId' => $this->serviceId, 'window' => $this->window]);
        $this->refreshCharts();
    }

    public function updatedServiceId(): void
    {
        $this->qualityOverview = [];
        $this->qualityOverviewLoaded = false;
        $this->qualityOverviewError = false;
        $this->resetValidation();
        $this->refreshCharts();
    }

    public function loadQualityOverview(): void
    {
        abort_unless(static::canAccess() && auth()->user()?->can('reliability.view'), 403);
        $service = $this->serviceId ? MonitoredService::query()->find($this->serviceId) : null;
        if (! $service || ! in_array($service->check_type->value, ['http', 'api'], true)) {
            $this->qualityOverview = [];
            $this->qualityOverviewLoaded = false;
            $this->qualityOverviewError = false;

            return;
        }

        try {
            $this->qualityOverview = app(ServiceQualityOverview::class)->forService($service);
            $this->qualityOverviewLoaded = true;
            $this->qualityOverviewError = false;
        } catch (Throwable $exception) {
            Log::error('Unable to load service quality overview.', ['service_id' => $service->id, 'exception' => $exception]);
            $this->qualityOverview = [];
            $this->qualityOverviewLoaded = false;
            $this->qualityOverviewError = true;
        }
    }

    public function reviewSloProposalAction(): Action
    {
        return Action::make('reviewSloProposal')
            ->label(__('slo_overview.actions.review'))
            ->visible(fn (): bool => $this->canManageSloProposal('draft'))
            ->requiresConfirmation()
            ->modalDescription(__('slo_overview.actions.review_description'))
            ->schema([
                Textarea::make('notes')->label(__('slo_overview.actions.review_notes'))->required()->maxLength(10000),
                Toggle::make('acknowledge')->label(__('slo_overview.actions.acknowledge'))->accepted()->required(),
            ])
            ->action(fn (array $data) => $this->transitionSloProposal('review', $data));
    }

    public function approveSloProposalAction(): Action
    {
        return Action::make('approveSloProposal')
            ->label(__('slo_overview.actions.approve'))
            ->visible(fn (): bool => $this->canManageSloProposal('reviewed')
                && ($this->qualityOverview['latest_proposal']['scientific_status'] ?? null) === 'provisional_research_only'
                && ($this->qualityOverview['latest_proposal']['configuration_compatible'] ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('slo_overview.actions.approve_description'))
            ->schema([Textarea::make('rationale')->label(__('slo_overview.actions.approval_rationale'))->required()->maxLength(10000)])
            ->action(fn (array $data) => $this->transitionSloProposal('approve', $data));
    }

    public function retireSloProposalAction(): Action
    {
        return Action::make('retireSloProposal')
            ->label(__('slo_overview.actions.retire'))
            ->color('danger')
            ->visible(fn (): bool => $this->canManageSloProposal('approved'))
            ->requiresConfirmation()
            ->modalDescription(__('slo_overview.actions.retire_description'))
            ->schema([Textarea::make('reason')->label(__('slo_overview.actions.retirement_reason'))->required()->maxLength(10000)])
            ->action(fn (array $data) => $this->transitionSloProposal('retire', $data));
    }

    private function canManageSloProposal(string $status): bool
    {
        return $this->qualityOverviewLoaded && static::canAccess()
            && (bool) auth()->user()?->can(SloProposalService::PERMISSION)
            && ($this->qualityOverview['latest_proposal']['status'] ?? null) === $status;
    }

    /** @param array<string, mixed> $data */
    private function transitionSloProposal(string $event, array $data): void
    {
        abort_unless(static::canAccess(), 403);
        Gate::authorize(SloProposalService::PERMISSION);
        $proposal = SloProposal::query()->where('monitored_service_id', $this->serviceId)
            ->latest('id')->firstOrFail();
        if ($proposal->id !== ($this->qualityOverview['latest_proposal']['id'] ?? null)) {
            Notification::make()->danger()->title(__('slo_overview.actions.stale'))->send();

            return;
        }

        try {
            $manager = app(SloProposalService::class);
            match ($event) {
                'review' => $manager->review($proposal, auth()->user(), $data['notes'], (bool) ($data['acknowledge'] ?? false)),
                'approve' => $manager->approve($proposal, auth()->user(), $data['rationale']),
                'retire' => $manager->retire($proposal, auth()->user(), $data['reason']),
            };
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('slo_overview.actions.failed'))
                ->body(collect($exception->errors())->flatten()->first())->send();

            return;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title(__('slo_overview.actions.failed'))->persistent()->send();

            return;
        }

        $this->loadQualityOverview();
        Notification::make()->success()->title(__('slo_overview.actions.success_'.$event))->send();
    }

    public function updatedWindow(): void
    {
        $this->resetValidation();
        $this->refreshCharts();
    }

    public function updatedCustomStart(): void
    {
        $this->resetValidation();
        $this->refreshCharts();
    }

    public function updatedCustomEnd(): void
    {
        $this->resetValidation();
        $this->refreshCharts();
    }

    private function refreshCharts(): void
    {
        $this->charts = [];
        $this->chartPayload = '{}';
        $this->summaryCache = null;
        $service = $this->serviceId ? MonitoredService::find($this->serviceId) : null;

        if (! $service || ! in_array($service->check_type->value, ['http', 'api'], true)) {
            return;
        }

        try {
            $period = $this->resolvedPeriod();
            if ($period === null) {
                return;
            }

            $charts = app(ControlChartCalculator::class)->analyzeReadOnly($service, $period[0], $period[1], 'hourly');
            $timezone = app(InstitutionTimezone::class)->name();
            $chartSummary = $charts['p_chart']['summary'];
            $coverage = app(MonitoringCoverageCalculator::class)->calculate($service, $period[0], $period[1]);
            $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
            $state = $chartSummary['eligible_count'] === 0
                ? 'no_data'
                : ($coverage['status'] === 'insufficient_coverage' ? 'insufficient' : ($chartSummary['eligible_count'] < $minimum ? 'preliminary' : 'sufficient'));
            $this->summaryCache = [
                'state' => $state, 'service' => $service, 'start' => $period[0], 'end' => $period[1],
                'eligible_count' => $chartSummary['eligible_count'], 'successful_count' => $chartSummary['successful_count'],
                'failed_count' => $chartSummary['failed_count'], 'latency_count' => $chartSummary['latency_count'],
                'successful_without_valid_latency' => $chartSummary['successful_without_valid_latency'],
                'problematic_count' => $chartSummary['problematic_count'], 'problematic_percent' => $chartSummary['problematic_percent'],
                'mean_latency' => $chartSummary['mean_latency'], 'coverage' => $coverage,
                'missing_buckets' => $chartSummary['missing_buckets'], 'buckets' => $chartSummary['buckets'],
            ];
            $displayCharts = [];
            foreach ($charts as $type => $chart) {
                $points = $chart['sufficiency'] === 'no_data'
                    ? []
                    : ($type === 'p_chart' ? ($chart['display_points'] ?? $chart['points']) : $chart['points']);
                $totalPoints = count($points);
                $points = $this->visualizationPoints($points);
                $displayCharts[$type] = [
                    'chart_type' => $type,
                    'center_line' => $chart['center_line'],
                    'ucl' => $chart['ucl'],
                    'lcl' => $chart['lcl'],
                    'sufficiency' => $chart['sufficiency'],
                    'sample_size' => $chart['sample_size'],
                    'aggregation_interval' => $chart['aggregation_interval'],
                    'period_start' => $chart['period_start']->toIso8601String(),
                    'period_end' => $chart['period_end']->toIso8601String(),
                    'analysis_timezone' => $timezone,
                    'visualization' => [
                        'calculated_points' => $totalPoints,
                        'displayed_points' => count($points),
                        'downsampled' => count($points) < $totalPoints,
                    ],
                    'points' => collect($points)->map(function (array $point) use ($timezone, $type): array {
                        $at = $point['point_time'] instanceof Carbon ? $point['point_time'] : Carbon::parse($point['point_time']);

                        $displayPoint = [
                            'label' => $at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
                            'value' => $point['value'],
                        ];
                        if ($type === 'p_chart') {
                            $displayPoint += [
                                'ucl' => $point['ucl'],
                                'lcl' => $point['lcl'],
                                'sample_size' => $point['sample_size'] ?? null,
                                'problematic_count' => data_get($point, 'research_context.problematic_count'),
                            ];
                        }
                        if ($point['is_out_of_control'] ?? false) {
                            $displayPoint['is_out_of_control'] = true;
                        }

                        return $displayPoint;
                    })->values()->all(),
                ];
            }
            $this->chartPayload = json_encode(array_map(fn (array $chart): array => $chart['points'], $displayCharts), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $this->charts = array_map(function (array $chart): array {
                unset($chart['points']);

                return $chart;
            }, $displayCharts);
        } catch (ValidationException|\InvalidArgumentException) {
            // The summary renders the validation message; charts remain empty.
        }
    }

    /**
     * Reduce only the browser payload while preserving research-relevant visual features.
     * The calculator and summary retain the complete population and all limits.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function visualizationPoints(array $points): array
    {
        $limit = max(100, (int) config('monitoring.spc.visualization_max_points', 2000));
        if (count($points) <= $limit) {
            return $points;
        }

        $mandatory = [];
        $optional = [];
        $lastIndex = array_key_last($points);
        foreach ($points as $index => $point) {
            if ($index === 0 || $index === $lastIndex || ($point['value'] ?? null) === null || ($point['is_out_of_control'] ?? false)) {
                $mandatory[$index] = $point;
            } else {
                $optional[$index] = $point;
            }
        }

        $budget = max(0, $limit - count($mandatory));
        if ($budget === 0) {
            ksort($mandatory);

            return array_values($mandatory);
        }

        $bucketCount = max(1, min(count($optional), intdiv($budget, 4)));
        $bucketSize = max(1, (int) ceil(count($optional) / $bucketCount));
        $selected = $mandatory;
        $optionalEntries = array_values($optional);
        $optionalIndexes = array_keys($optional);
        for ($offset = 0; $offset < count($optionalEntries) && count($selected) < $limit; $offset += $bucketSize) {
            $slice = array_slice($optionalEntries, $offset, $bucketSize, true);
            $sliceIndexes = array_slice($optionalIndexes, $offset, $bucketSize, true);
            $first = array_key_first($slice);
            $last = array_key_last($slice);
            $min = $first;
            $max = $first;
            foreach ($slice as $relative => $candidate) {
                if ($candidate['value'] < $slice[$min]['value']) {
                    $min = $relative;
                }
                if ($candidate['value'] > $slice[$max]['value']) {
                    $max = $relative;
                }
            }
            foreach ([$first, $min, $max, $last] as $relative) {
                if ($relative === null || count($selected) >= $limit) {
                    continue;
                }
                $selected[$sliceIndexes[$relative]] = $slice[$relative];
            }
        }
        ksort($selected);

        return array_values($selected);
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function resolvedPeriod(): ?array
    {
        if (! $this->serviceId) {
            return null;
        }

        try {
            if ($this->window === 'custom') {
                if ($this->customStart === '' || $this->customEnd === '') {
                    return null;
                }
                $zone = app(InstitutionTimezone::class)->name();
                $start = Carbon::createFromFormat('Y-m-d\\TH:i', $this->customStart, $zone);
                $end = Carbon::createFromFormat('Y-m-d\\TH:i', $this->customEnd, $zone);
                if ($end->lte($start)) {
                    throw ValidationException::withMessages(['customEnd' => __('performance_analysis.invalid_reversed')]);
                }
                if ($start->gt(now($zone))) {
                    throw ValidationException::withMessages(['customStart' => __('performance_analysis.future_only')]);
                }
                if ($end->gt(now($zone))) {
                    $end = now($zone);
                }

                return [$start->utc(), $end->utc()];
            }

            return app(SpcAnalysisWindow::class)->resolve($this->window);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['window' => __('performance_analysis.invalid_range')]);
        }
    }

    /** @return array<string, mixed> */
    private function summary(?MonitoredService $service): array
    {
        if (! $service) {
            return ['state' => 'select_service'];
        }
        if (! in_array($service->check_type->value, ['http', 'api'], true)) {
            return ['state' => 'unsupported', 'service' => $service];
        }
        try {
            $period = $this->resolvedPeriod();
        } catch (ValidationException $exception) {
            return ['state' => 'invalid', 'errors' => $exception->errors()];
        }
        if ($period === null) {
            return ['state' => 'missing_period', 'service' => $service];
        }
        [$start, $end] = $period;
        $research = app(SpcResearchData::class);
        $binary = $research->checks($service, $start, $end);
        $latency = $research->checks($service, $start, $end, true);
        $buckets = $research->buckets($service, $start, $end, 'hourly', app(InstitutionTimezone::class)->name());
        $coverage = app(MonitoringCoverageCalculator::class)->calculate($service, $start, $end);
        $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
        $state = $binary->isEmpty() ? 'no_data' : ($coverage['status'] === 'insufficient_coverage' ? 'insufficient' : ($binary->count() < $minimum ? 'preliminary' : 'sufficient'));

        return [
            'state' => $state, 'service' => $service, 'start' => $start, 'end' => $end,
            'eligible_count' => $binary->count(), 'successful_count' => $binary->where('is_success', true)->count(),
            'failed_count' => $binary->where('is_success', false)->count(), 'latency_count' => $latency->count(),
            'successful_without_valid_latency' => max(0, $binary->where('is_success', true)->count() - $latency->count()),
            'problematic_count' => $binary->filter(fn ($check): bool => $check->is_problematic)->count(),
            'problematic_percent' => $binary->count() ? 100 * $binary->filter(fn ($check): bool => $check->is_problematic)->count() / $binary->count() : null,
            'mean_latency' => $latency->avg('response_time_ms'), 'coverage' => $coverage,
            'missing_buckets' => $buckets->where('missing_count', '>', 0)->count(),
            'buckets' => $buckets->count(),
        ];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'summary' => $this->summaryCache ?? $this->summary($this->serviceId ? MonitoredService::find($this->serviceId) : null),
            'timezone' => app(InstitutionTimezone::class)->name(),
        ];
    }
}
