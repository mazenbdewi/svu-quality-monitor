<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ControlChart;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class ControlChartsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;
    use FormatsReportValues;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        protected array $filters = [],
        protected ?string $sheetTitle = null,
    ) {}

    public function query(): Builder
    {
        return ControlChart::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
            ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
            ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date))
            ->orderBy('period_start');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            __('monitoring.report_columns.service_name'),
            __('monitoring.report_columns.chart_type'),
            __('monitoring.report_columns.metric_name'),
            __('monitoring.report_columns.period_type'),
            __('monitoring.report_columns.period_start'),
            __('monitoring.report_columns.period_end'),
            __('monitoring.report_columns.center_line'),
            __('monitoring.report_columns.ucl'),
            __('monitoring.report_columns.lcl'),
            __('monitoring.report_columns.points_count'),
            __('monitoring.report_columns.out_of_control_count'),
            __('monitoring.report_columns.calculated_at'),
            'analysis_timezone', 'aggregation_interval', 'analysis_mode', 'data_cutoff', 'calculation_version', 'sufficiency',
        ];
    }

    /**
     * @param  ControlChart  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $this->chartTypeLabel($row->chart_type),
            $this->metricNameLabel($row->metric_name),
            $this->periodTypeLabel($row->period_type),
            $this->dateTime($row->period_start),
            $this->dateTime($row->period_end),
            $row->center_line,
            $row->ucl,
            $row->lcl,
            $row->points_count,
            $row->out_of_control_count,
            $this->dateTime($row->calculated_at),
            $row->analysis_timezone, $row->aggregation_interval, $row->analysis_mode ?? 'legacy',
            $this->dateTime($row->data_cutoff), $row->calculation_version, data_get($row->research_context, 'sufficiency', 'legacy'),
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle ?? __('monitoring.report_types.control_charts');
    }
}
