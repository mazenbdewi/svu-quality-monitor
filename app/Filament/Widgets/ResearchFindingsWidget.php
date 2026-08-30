<?php

namespace App\Filament\Widgets;

use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Services\ResearchInterpretationService;
use Filament\Widgets\Widget;

class ResearchFindingsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.research-findings-widget';

    /** @return array{cards: array<int, array{title: string, value: string, indicator: string, status: string, color: string, message: string}>} */
    protected function getViewData(): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        $todayChecks = ServiceCheck::query()->whereBetween('checked_at', [$start, $end])->count();
        $slowChecks = ServiceCheck::query()->whereBetween('checked_at', [$start, $end])->where('is_slow', true)->count();
        $availability = ReliabilityMetric::query()->where('period_type', 'daily')->whereDate('period_start', today())->avg('availability_percent');
        $hasSpcData = ControlChart::query()->where('points_count', '>', 0)->exists();
        $outOfControlPoints = ControlChartPoint::query()->where('is_out_of_control', true)->whereBetween('point_time', [$start, $end])->count();
        $performance = $this->performanceState($slowChecks, $todayChecks);
        $reliability = $this->reliabilityState($availability);
        $spc = $this->spcState($hasSpcData, $outOfControlPoints);

        return ['cards' => [
            [
                'title' => __('monitoring.dashboard.research_cards.performance'),
                'value' => number_format($slowChecks),
                'indicator' => __('monitoring.dashboard.research_cards.slow_checks_today'),
                'status' => $performance['label'],
                'color' => $performance['color'],
                'message' => $todayChecks > 0 ? __('monitoring.dashboard.research_cards.slow_checks_ratio', ['slow' => number_format($slowChecks), 'total' => number_format($todayChecks)]) : __('monitoring.dashboard.research_cards.no_checks_today'),
            ],
            [
                'title' => __('monitoring.dashboard.research_cards.reliability'),
                'value' => $availability === null ? '—' : number_format((float) $availability, 2).'%',
                'indicator' => __('monitoring.dashboard.research_cards.average_availability'),
                'status' => $reliability['label'],
                'color' => $reliability['color'],
                'message' => $reliability['message'],
            ],
            [
                'title' => __('monitoring.dashboard.research_cards.spc'),
                'value' => $hasSpcData ? number_format($outOfControlPoints) : '—',
                'indicator' => __('monitoring.dashboard.research_cards.out_of_control_points'),
                'status' => $spc['label'],
                'color' => $spc['color'],
                'message' => $spc['message'],
            ],
        ]];
    }

    /** @return array{label: string, color: string} */
    private function performanceState(int $slowChecks, int $todayChecks): array
    {
        if ($slowChecks === 0) {
            return ['label' => __('monitoring.interpretation.levels.excellent'), 'color' => 'success'];
        }

        if ($todayChecks > 0 && ($slowChecks / $todayChecks) <= 0.05) {
            return ['label' => __('monitoring.interpretation.levels.acceptable'), 'color' => 'info'];
        }

        return ['label' => __('monitoring.interpretation.levels.needs_attention'), 'color' => 'warning'];
    }

    /** @return array{label: string, color: string, message: string} */
    private function reliabilityState(?float $availability): array
    {
        if ($availability === null) {
            return ['label' => __('monitoring.dashboard.research_cards.awaiting_calculation'), 'color' => 'gray', 'message' => __('monitoring.dashboard.research_cards.reliability_waiting_message')];
        }

        $interpretation = app(ResearchInterpretationService::class);
        $level = $interpretation->availabilityLevel($availability);

        return ['label' => __("monitoring.interpretation.levels.{$level}"), 'color' => $interpretation->availabilityColor($availability), 'message' => __('monitoring.dashboard.research_cards.reliability_average_message')];
    }

    /** @return array{label: string, color: string, message: string} */
    private function spcState(bool $hasSpcData, int $outOfControlPoints): array
    {
        if (! $hasSpcData) {
            return ['label' => __('monitoring.dashboard.research_cards.awaiting_data'), 'color' => 'gray', 'message' => __('monitoring.dashboard.research_cards.spc_waiting_message')];
        }

        if ($outOfControlPoints === 0) {
            return ['label' => __('monitoring.interpretation.levels.stable'), 'color' => 'success', 'message' => __('monitoring.dashboard.research_cards.spc_stable_message')];
        }

        return ['label' => __('monitoring.interpretation.levels.warning'), 'color' => 'warning', 'message' => __('monitoring.dashboard.research_cards.spc_attention_message')];
    }

}
