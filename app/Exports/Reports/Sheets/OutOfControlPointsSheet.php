<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ControlChartPoint;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class OutOfControlPointsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;
    use FormatsReportValues;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        return ControlChartPoint::query()
            ->with('controlChart.monitoredService:id,name')
            ->where('is_out_of_control', true)
            ->whereHas('controlChart', function (Builder $query): void {
                $query
                    ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
                    ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
                    ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
                    ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
                    ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
                    ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
            })
            ->orderBy('point_time');
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
            __('monitoring.report_columns.point_time'),
            __('monitoring.report_columns.value'),
            __('monitoring.report_columns.center_line'),
            __('monitoring.report_columns.ucl'),
            __('monitoring.report_columns.lcl'),
            __('monitoring.report_columns.sample_size'),
            __('monitoring.report_columns.failed_count'),
            __('monitoring.report_columns.signal_type'),
            __('monitoring.report_columns.note'),
        ];
    }

    /**
     * @param  ControlChartPoint  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->controlChart?->monitoredService?->name,
            $this->chartTypeLabel($row->controlChart?->chart_type),
            $this->metricNameLabel($row->controlChart?->metric_name),
            $this->dateTime($row->point_time),
            $row->value,
            $row->center_line,
            $row->ucl,
            $row->lcl,
            $row->sample_size,
            $row->failed_count,
            $this->signalTypeLabel($row->signal_type),
            $row->note,
        ];
    }

    public function title(): string
    {
        return __('monitoring.reports.sheets.out_of_control_points');
    }
}
