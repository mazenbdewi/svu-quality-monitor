@php
    $points = $record->points()
        ->orderBy('point_time')
        ->get();

    $chartId = 'control-chart-graph-'.$record->getKey();
    $chartData = [
        'labels' => $points
            ->map(fn ($point): string => $point->point_time->format('Y-m-d H:i'))
            ->all(),
        'datasets' => [
            [
                'label' => __('monitoring.control_charts.graph.datasets.actual_value'),
                'data' => $points
                    ->map(fn ($point): float => (float) $point->value)
                    ->all(),
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                'borderWidth' => 3,
                'pointBackgroundColor' => $points
                    ->map(fn ($point): string => $point->is_out_of_control ? '#dc2626' : '#2563eb')
                    ->all(),
                'pointBorderColor' => $points
                    ->map(fn ($point): string => $point->is_out_of_control ? '#dc2626' : '#2563eb')
                    ->all(),
                'pointRadius' => $points
                    ->map(fn ($point): int => $point->is_out_of_control ? 6 : 4)
                    ->all(),
                'pointHoverRadius' => 8,
                'tension' => 0.25,
            ],
            [
                'label' => __('monitoring.control_charts.graph.datasets.center_line'),
                'data' => $points
                    ->map(fn ($point): ?float => $point->center_line === null ? null : (float) $point->center_line)
                    ->all(),
                'borderColor' => '#16a34a',
                'borderWidth' => 2,
                'borderDash' => [6, 5],
                'pointRadius' => 0,
            ],
            [
                'label' => __('monitoring.control_charts.graph.datasets.ucl'),
                'data' => $points
                    ->map(fn ($point): ?float => $point->ucl === null ? null : (float) $point->ucl)
                    ->all(),
                'borderColor' => '#dc2626',
                'borderWidth' => 2,
                'borderDash' => [8, 5],
                'pointRadius' => 0,
            ],
            [
                'label' => __('monitoring.control_charts.graph.datasets.lcl'),
                'data' => $points
                    ->map(fn ($point): ?float => $point->lcl === null ? null : (float) $point->lcl)
                    ->all(),
                'borderColor' => '#f59e0b',
                'borderWidth' => 2,
                'borderDash' => [8, 5],
                'pointRadius' => 0,
            ],
        ],
    ];
@endphp

@if ($points->isEmpty())
    <div class="rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('monitoring.control_charts.empty.not_enough_points') }}
    </div>
@else
    <div class="h-[420px] w-full" wire:ignore>
        <canvas id="{{ $chartId }}"></canvas>
    </div>

    @once
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    @endonce

    <script>
        (() => {
            const renderControlChart = () => {
                const canvas = document.getElementById(@js($chartId));

                if (! canvas || ! window.Chart || canvas.dataset.rendered === 'true') {
                    return;
                }

                canvas.dataset.rendered = 'true';

                new Chart(canvas, {
                    type: 'line',
                    data: @js($chartData),
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                            tooltip: {
                                callbacks: {
                                    label: (context) => {
                                        const label = context.dataset.label || '';
                                        const value = context.parsed.y;

                                        if (value === null || value === undefined) {
                                            return label;
                                        }

                                        return `${label}: ${Number(value).toLocaleString(undefined, {
                                            maximumFractionDigits: 4,
                                        })}`;
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 0,
                                },
                            },
                            y: {
                                beginAtZero: true,
                            },
                        },
                    },
                });
            };

            if (window.Chart) {
                renderControlChart();
            } else {
                document.addEventListener('DOMContentLoaded', renderControlChart, { once: true });
            }
        })();
    </script>
@endif
