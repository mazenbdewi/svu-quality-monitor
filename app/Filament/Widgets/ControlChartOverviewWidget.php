<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Models\ControlChart;
use Carbon\Carbon;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class ControlChartOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public ControlChart $record;

    /** @var int | array<string, ?int> | null */
    protected int|array|null $columns = [
        '@xl' => 5,
        '@lg' => 3,
        '!@lg' => 2,
    ];

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $status = (int) $this->record->out_of_control_count > 0 ? 'out_of_control' : 'normal';

        return [
            Stat::make(__('monitoring.control_charts.summary.service'), $this->record->monitoredService?->name ?? __('monitoring.dashboard.empty.value'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.chart_type'), ControlChartResource::chartTypeOptions()[$this->record->chart_type] ?? $this->record->chart_type)
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.metric_name'), ControlChartResource::metricNameOptions()[$this->record->metric_name] ?? $this->record->metric_name)
                ->icon(Heroicon::OutlinedChartBar)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.period'), $this->ltr($this->formatDateTime($this->record->period_start).' — '.$this->formatDateTime($this->record->period_end)))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.points_count'), $this->ltr(number_format((int) $this->record->points_count)))
                ->icon(Heroicon::OutlinedNumberedList)
                ->color('gray'),
            Stat::make('CL', $this->ltr($this->formatNumber($this->record->center_line)))
                ->description(__('monitoring.control_charts.summary.center_line'))
                ->icon(Heroicon::OutlinedMinus)
                ->color('info'),
            Stat::make('UCL', $this->ltr($this->formatNumber($this->record->ucl)))
                ->description(__('monitoring.control_charts.summary.ucl'))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('danger'),
            Stat::make('LCL', $this->ltr($this->formatNumber($this->record->lcl)))
                ->description(__('monitoring.control_charts.summary.lcl'))
                ->icon(Heroicon::OutlinedArrowTrendingDown)
                ->color('warning'),
            Stat::make(__('monitoring.control_charts.summary.out_of_control_points'), $this->ltr(number_format((int) $this->record->out_of_control_count)))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color((int) $this->record->out_of_control_count > 0 ? 'danger' : 'success'),
            Stat::make(__('monitoring.control_charts.summary.process_status'), __("monitoring.control_charts.statuses.{$status}"))
                ->icon($status === 'normal' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($status === 'normal' ? 'success' : 'danger'),
            Stat::make(__('monitoring.control_charts.summary.calculated_at'), $this->ltr($this->formatDateTime($this->record->calculated_at)))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray'),
        ];
    }

    private function formatNumber(mixed $value): string
    {
        return $value === null ? __('monitoring.dashboard.empty.value') : number_format((float) $value, 2);
    }

    private function formatDateTime(mixed $value): string
    {
        if (! $value) {
            return __('monitoring.dashboard.empty.value');
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('d/m/Y H:i');
    }

    private function ltr(string $value): HtmlString
    {
        return new HtmlString('<span dir="ltr" class="[unicode-bidi:isolate]">'.e($value).'</span>');
    }
}
