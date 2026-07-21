<?php

namespace App\Filament\Widgets;

use App\Models\ServiceCheck;
use Filament\Widgets\ChartWidget;

class ChecksStatusDistributionWidget extends ChartWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string
    {
        return __('monitoring.dashboard.widgets.checks_status_distribution');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $query = ServiceCheck::query()
            ->whereBetween('checked_at', [now()->startOfDay(), now()->endOfDay()]);

        $successful = (clone $query)
            ->where('is_success', true)
            ->where('is_slow', false)
            ->count();
        $slow = (clone $query)
            ->where('is_success', true)
            ->where('is_slow', true)
            ->count();
        $failed = (clone $query)
            ->where('is_success', false)
            ->count();

        return [
            'datasets' => [
                [
                    'data' => [$successful, $slow, $failed],
                    'backgroundColor' => ['#22c55e', '#f59e0b', '#ef4444'],
                ],
            ],
            'labels' => [
                __('monitoring.dashboard.chart_labels.healthy'),
                __('monitoring.dashboard.chart_labels.slow'),
                __('monitoring.dashboard.chart_labels.failed'),
            ],
        ];
    }
}
