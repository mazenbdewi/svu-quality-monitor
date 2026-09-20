<?php

namespace App\Filament\Pages;

use App\Models\ControlChartBaseline;
use App\Models\SpcIncidentLink;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcResearchRun;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Spc\SpcResearchEvaluation;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpcResearchMonitoring extends Page
{
    protected string $view = 'filament.pages.spc-research-monitoring';

    protected static ?int $navigationSort = 13;

    public ?int $baselineId = null;

    public string $mode = 'live';

    public string $periodStart = '';

    public string $periodEnd = '';

    public ?int $runId = null;

    public static function getNavigationLabel(): string
    {
        return __('spc_phase2.title');
    }

    public function getTitle(): string
    {
        return __('spc_phase2.title');
    }

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_active && auth()->user()?->can('control_charts.view'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->baselineId = ControlChartBaseline::where('status', 'approved')->latest('id')->value('id');
        $this->periodStart = now()->utc()->subDays(7)->format('Y-m-d\TH:i');
        $this->periodEnd = now()->utc()->format('Y-m-d\TH:i');
    }

    public function updatedMode(): void
    {
        $this->runId = null;
    }

    public function updatedBaselineId(): void
    {
        $this->runId = null;
    }

    public function generateReport(): void
    {
        abort_unless(static::canAccess(), 403);
        $actor = auth()->user()->fresh();
        Gate::forUser($actor)->authorize(PhaseOneBaselineService::PERMISSION);
        $this->validate(['baselineId' => 'required|integer|exists:control_chart_baselines,id', 'mode' => 'required|in:live,retrospective', 'periodStart' => 'required|date_format:Y-m-d\TH:i', 'periodEnd' => 'required|date_format:Y-m-d\TH:i|after:periodStart']);
        $baseline = ControlChartBaseline::findOrFail($this->baselineId);
        $run = app(SpcResearchEvaluation::class)->run(Carbon::parse($this->periodStart, 'UTC'), Carbon::parse($this->periodEnd, 'UTC'), $this->mode, serviceId: $baseline->monitored_service_id, chartType: $baseline->chart_type);
        $this->runId = $run->id;
    }

    public function exportReport(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $run = SpcResearchRun::findOrFail($this->runId);
        $data = ['run' => $run->toArray(), 'links' => SpcIncidentLink::where('research_run_id', $run->id)->get()->toArray()];

        return response()->streamDownload(fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'spc-research-run-'.$run->id.'.json', ['Content-Type' => 'application/json']);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $baseline = ControlChartBaseline::find($this->baselineId);
        $points = SpcMonitoringPoint::where('baseline_id', $baseline?->id)->where('mode', $this->mode)->latest('observed_at')->latest('id')->limit(150)->get()->reverse()->values();
        $signals = SpcSignal::where('baseline_id', $baseline?->id)->where('mode', $this->mode)->latest('id')->limit(30)->get();
        $episodeRows = SpcSignalEpisode::where('baseline_id', $baseline?->id)->where('mode', $this->mode)->latest('id')->limit(30)->get();
        $run = $this->runId ? SpcResearchRun::find($this->runId) : null;

        return compact('baseline', 'points', 'signals', 'episodeRows', 'run') + [
            'runs' => SpcResearchRun::where('mode', $this->mode)->latest('id')->limit(30)->get()->filter(fn ($r) => collect($r->baseline_versions)->contains('id', $baseline?->id)),
            'baselines' => ControlChartBaseline::with('monitoredService')->whereNotNull('approved_at')->latest('id')->get(),
            'compatible' => $baseline ? app(PhaseOneBaselineService::class)->compatible($baseline) : false,
            'latestEvaluation' => $baseline ? SpcMonitoringEvaluation::where('service_id', $baseline->monitored_service_id)->where('chart_type', $baseline->chart_type)->where('mode', $this->mode)->latest('id')->first() : null,
            'links' => $run ? SpcIncidentLink::where('research_run_id', $run->id)->get() : collect(),
        ];
    }
}
