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

    protected string $view = 'filament.widgets.research-findings-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        $interpretation = app(ResearchInterpretationService::class);
        $slowChecks = ServiceCheck::query()
            ->whereBetween('checked_at', [$start, $end])
            ->where('is_slow', true)
            ->count();
        $availability = ReliabilityMetric::query()
            ->where('period_type', 'daily')
            ->whereDate('period_start', today())
            ->avg('availability_percent');
        $outOfControlPoints = ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->whereBetween('point_time', [$start, $end])
            ->count();
        $hasSpcData = ControlChart::query()->where('points_count', '>', 0)->exists();
        $availabilityLevel = $availability === null ? 'no_data' : $interpretation->availabilityLevel((float) $availability);

        return [
            'performance' => [
                'value' => number_format($slowChecks),
                'color' => $slowChecks > 0 ? 'warning' : 'success',
                'message' => $slowChecks > 0
                    ? __('monitoring.dashboard.research_cards.performance_slow_checks')
                    : __('monitoring.dashboard.research_cards.performance_no_slow_checks'),
            ],
            'reliability' => [
                'value' => $availability === null ? null : number_format((float) $availability, 2).'%',
                'level' => $availabilityLevel,
                'color' => $interpretation->availabilityColor($availability === null ? null : (float) $availability),
            ],
            'spc' => [
                'value' => number_format($outOfControlPoints),
                'has_data' => $hasSpcData,
                'color' => $outOfControlPoints > 0 ? 'warning' : 'success',
            ],
        ];
    }
}
