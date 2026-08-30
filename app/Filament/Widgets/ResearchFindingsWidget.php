<?php

namespace App\Filament\Widgets;

use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Services\ResearchInterpretationService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class ResearchFindingsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    /** @var int | array<string, ?int> | null */
    protected int|array|null $columns = ['@xl' => 3, '@lg' => 2, '!@lg' => 1];

    protected function getHeading(): ?string
    {
        return __('monitoring.interpretation.sections.key_findings');
    }

    /** @return array<Stat> */
    protected function getStats(): array
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

        return [
            Stat::make(__('monitoring.dashboard.research_cards.performance'), number_format($slowChecks))
                ->description($this->description(__('monitoring.dashboard.research_cards.slow_checks_today'), $performance['label'], $performance['color'], $todayChecks > 0 ? __('monitoring.dashboard.research_cards.slow_checks_ratio', ['slow' => number_format($slowChecks), 'total' => number_format($todayChecks)]) : __('monitoring.dashboard.research_cards.no_checks_today')))
                ->icon(Heroicon::OutlinedChartBar)
                ->color($performance['color']),
            Stat::make(__('monitoring.dashboard.research_cards.reliability'), $availability === null ? '—' : number_format((float) $availability, 2).'%')
                ->description($this->description(__('monitoring.dashboard.research_cards.average_availability'), $reliability['label'], $reliability['color'], $reliability['message']))
                ->icon(Heroicon::OutlinedChartBarSquare)
                ->color($reliability['color']),
            Stat::make(__('monitoring.dashboard.research_cards.spc'), $hasSpcData ? number_format($outOfControlPoints) : '—')
                ->description($this->description(__('monitoring.dashboard.research_cards.out_of_control_points'), $spc['label'], $spc['color'], $spc['message']))
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color($spc['color']),
        ];
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

    private function description(string $indicator, string $status, string $color, string $message): HtmlString
    {
        return new HtmlString(view('filament.widgets.research-indicator-stat-description', compact('indicator', 'status', 'color', 'message'))->render());
    }
}
