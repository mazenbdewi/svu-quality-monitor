<?php

namespace App\Filament\Pages;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcSignal;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\InstitutionTimezone;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class EarlyDeteriorationDetection extends Page
{
    protected static ?string $slug = 'early-deterioration-detection';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected string $view = 'filament.pages.early-deterioration-detection';

    public ?int $serviceId = null;

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_active && auth()->user()?->can('control_charts.view'));
    }

    public static function getNavigationLabel(): string
    {
        return __('early_deterioration.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.advanced_research');
    }

    public function getTitle(): string
    {
        return __('early_deterioration.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $requested = request()->query('service');
        $this->serviceId = is_scalar($requested) && ctype_digit((string) $requested)
            ? MonitoredService::whereKey((int) $requested)->value('id')
            : MonitoredService::orderBy('name')->value('id');
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $services = MonitoredService::query()->orderBy('name')->get(['id', 'name', 'check_type', 'warning_response_ms', 'is_active']);
        $service = $services->firstWhere('id', $this->serviceId);
        $timezone = app(InstitutionTimezone::class)->name();
        $rows = [];

        if ($service) {
            $baselines = ControlChartBaseline::query()
                ->where('monitored_service_id', $service->id)
                ->where('status', 'approved')
                ->whereNotNull('approved_at')
                ->where('approved_at', '<=', now())
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->orderByDesc('version')->get()->unique('chart_type')->keyBy('chart_type');

            foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
                $baseline = $baselines->get($type);
                $compatible = $baseline ? app(PhaseOneBaselineService::class)->compatible($baseline) : false;
                $evaluation = $baseline ? SpcMonitoringEvaluation::query()
                    ->where('baseline_id', $baseline->id)->where('mode', 'live')
                    ->latest('detected_at')->latest('id')->first() : null;
                $point = $evaluation?->status === 'active' ? SpcMonitoringPoint::query()
                    ->where('evaluation_id', $evaluation->id)->where('mode', 'live')
                    ->latest('observed_at')->latest('id')->first() : null;
                $pointSignal = $point?->status === 'eligible' ? SpcSignal::query()
                    ->where('point_id', $point->id)->where('mode', 'live')->first() : null;
                $latestLiveSignal = SpcSignal::query()
                    ->where('service_id', $service->id)->where('chart_type', $type)->where('mode', 'live')
                    ->latest('detected_at')->latest('id')->first();
                $latestRetrospectiveSignal = SpcSignal::query()
                    ->where('service_id', $service->id)->where('chart_type', $type)->where('mode', 'retrospective')
                    ->latest('detected_at')->latest('id')->first();

                $state = match (true) {
                    ! $service->is_active => 'inactive',
                    $baseline === null => 'not_configured',
                    ! $compatible, $evaluation?->status === 'baseline_incompatible' => 'incompatible',
                    $evaluation === null => 'awaiting_evaluation',
                    $evaluation->status !== 'active' => $evaluation->status,
                    $evaluation->exposure_until === null || $evaluation->exposure_until->lte(now()) => 'stale',
                    $point?->status !== 'eligible' => 'no_current_point',
                    $pointSignal?->direction === 'lower' => 'unusual_change',
                    $pointSignal !== null => 'signal',
                    default => 'normal',
                };

                $rows[] = compact('type', 'baseline', 'evaluation', 'point', 'pointSignal', 'latestLiveSignal', 'latestRetrospectiveSignal', 'state');
            }
        }

        return compact('services', 'service', 'rows', 'timezone');
    }
}
