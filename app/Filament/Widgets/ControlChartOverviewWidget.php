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
            Stat::make(__('monitoring.control_charts.summary.service'), $this->text($this->record->monitoredService?->name ?? __('monitoring.dashboard.empty.value')))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.chart_type'), $this->text(ControlChartResource::chartTypeOptions()[$this->record->chart_type] ?? $this->record->chart_type))
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.metric_name'), $this->text(ControlChartResource::metricNameOptions()[$this->record->metric_name] ?? $this->record->metric_name))
                ->icon(Heroicon::OutlinedChartBar)
                ->color('info'),
            Stat::make(__('monitoring.control_charts.summary.period_from'), $this->ltr($this->formatDate($this->record->period_start), 'text-lg leading-6'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.period_until'), $this->ltr($this->formatDate($this->record->period_end), 'text-lg leading-6'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.points_count'), $this->ltr(number_format((int) $this->record->points_count)))
                ->icon(Heroicon::OutlinedNumberedList)
                ->color('gray'),
            Stat::make($this->ltr('CL', 'text-sm'), $this->ltr($this->formatNumber($this->record->center_line)))
                ->description(__('monitoring.control_charts.summary.center_line'))
                ->icon(Heroicon::OutlinedMinus)
                ->color('info'),
            Stat::make($this->ltr('UCL', 'text-sm'), $this->ltr($this->formatNumber($this->record->ucl)))
                ->description(__('monitoring.control_charts.summary.ucl'))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('danger'),
            Stat::make($this->ltr('LCL', 'text-sm'), $this->ltr($this->formatNumber($this->record->lcl)))
                ->description(__('monitoring.control_charts.summary.lcl'))
                ->icon(Heroicon::OutlinedArrowTrendingDown)
                ->color('warning'),
            Stat::make(__('monitoring.control_charts.summary.out_of_control_points'), $this->ltr(number_format((int) $this->record->out_of_control_count)))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color((int) $this->record->out_of_control_count > 0 ? 'danger' : 'success'),
            Stat::make(__('monitoring.control_charts.summary.process_status'), $this->text(__("monitoring.control_charts.statuses.{$status}")))
                ->icon($status === 'normal' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                ->color($status === 'normal' ? 'success' : 'danger'),
            Stat::make(__('monitoring.control_charts.summary.calculated_date'), $this->ltr($this->formatDate($this->record->calculated_at), 'text-lg leading-6'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.calculated_at'), $this->ltr($this->formatTime($this->record->calculated_at), 'text-lg leading-6'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray'),
        ];
    }

    private function formatNumber(mixed $value): string
    {
        return $value === null ? __('monitoring.dashboard.empty.value') : number_format((float) $value, 2);
    }

    private function formatDate(mixed $value): string
    {
        if (! $value) {
            return __('monitoring.dashboard.empty.value');
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('d/m/Y');
    }

    private function formatTime(mixed $value): string
    {
        if (! $value) {
            return __('monitoring.dashboard.empty.value');
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('H:i');
    }

    private function ltr(string $value, string $classes = 'text-2xl leading-7'): HtmlString
    {
        return new HtmlString('<span dir="ltr" class="[unicode-bidi:isolate] '.$classes.'">'.e($value).'</span>');
    }

    private function text(string $value): HtmlString
    {
        return new HtmlString('<span class="text-lg leading-6">'.e($value).'</span>');
    }
}
