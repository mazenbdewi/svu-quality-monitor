<?php

namespace App\Filament\Widgets;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Filament\Widgets\ChartWidget;

class ResponseTimeTrendWidget extends ChartWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public ?string $filter = 'all';

    protected function getType(): string
    {
        return 'line';
    }

    public function getHeading(): string
    {
        return __('monitoring.dashboard.widgets.response_time_trend');
    }

    /** @return array<string, string> */
    protected function getFilters(): array
    {
        return ['all' => __('monitoring.dashboard.filters.all_services')]
            + MonitoredService::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
                ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $checks = ServiceCheck::query()
            ->whereBetween('checked_at', [now()->subHours(24), now()])
            ->whereNotNull('response_time_ms')
            ->when($this->filter && $this->filter !== 'all', fn ($query) => $query->where('monitored_service_id', $this->filter))
            ->orderBy('checked_at')
            ->get(['checked_at', 'response_time_ms'])
            ->groupBy(fn (ServiceCheck $check): int => (int) $check->checked_at->format('G'));

        $labels = [];
        $data = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00';
            $hourChecks = $checks->get($hour);
            $data[] = $hourChecks ? round((float) $hourChecks->avg('response_time_ms'), 2) : null;
        }

        return [
            'datasets' => [
                [
                    'label' => __('monitoring.dashboard.columns.average_response_time'),
                    'data' => $data,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.16)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
