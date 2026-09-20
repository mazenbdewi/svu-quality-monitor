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

    protected string $view = 'filament.widgets.control-chart-overview-widget';

    public ControlChart $record;

    /** @var int | array<string, ?int> | null */
    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $status = $this->record->analysis_mode === 'exploratory' ? data_get($this->record->research_context, 'sufficiency', 'insufficient') : 'legacy';

        return [
            Stat::make(__('monitoring.control_charts.summary.process_status'), $this->text(__("monitoring.spc_research.{$status}")))
                ->description(__('monitoring.spc_research.limits'))
                ->icon(Heroicon::OutlinedInformationCircle)
                ->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.out_of_control_points'), $this->ltr(number_format((int) $this->record->out_of_control_count)))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color((int) $this->record->out_of_control_count > 0 ? 'danger' : 'success'),
            Stat::make(__('monitoring.control_charts.summary.chart_type'), $this->text(ControlChartResource::chartTypeOptions()[$this->record->chart_type] ?? $this->record->chart_type))
                ->description(__('ux.help.control_chart'))->color('gray'),
            Stat::make(__('monitoring.control_charts.summary.period'), $this->ltr($this->formatDate($this->record->period_start).' — '.$this->formatDate($this->record->period_end), 'cc-card-value'))
                ->description($this->formatTime($this->record->calculated_at))->color('gray'),
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

    private function ltr(string $value, string $classes = 'cc-card-value-number'): HtmlString
    {
        return new HtmlString('<span dir="ltr" class="[unicode-bidi:isolate] '.$classes.'">'.e($value).'</span>');
    }

    private function text(string $value): HtmlString
    {
        return new HtmlString('<span class="cc-card-value">'.e($value).'</span>');
    }

    private function secondary(string $value): HtmlString
    {
        return new HtmlString('<span class="cc-card-description">'.e($value).'</span>');
    }
}
