<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ReliabilityMetric;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class ReliabilityMetricsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
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
        return ReliabilityMetric::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
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
            __('monitoring.report_columns.period_type'),
            __('monitoring.report_columns.period_start'),
            __('monitoring.report_columns.period_end'),
            __('monitoring.report_columns.total_checks'),
            __('monitoring.report_columns.successful_checks'),
            __('monitoring.report_columns.failed_checks'),
            __('monitoring.report_columns.incidents_count'),
            __('monitoring.report_columns.uptime_minutes'),
            __('monitoring.report_columns.downtime_minutes'),
            __('monitoring.report_columns.planned_maintenance_minutes'),
            __('monitoring.report_columns.observation_minutes'),
            __('monitoring.report_columns.availability_percent'),
            __('monitoring.report_columns.mtbf_minutes'),
            __('monitoring.report_columns.mttr_minutes'),
            __('monitoring.report_columns.failure_rate'),
            __('monitoring.report_columns.calculated_at'),
        ];
    }

    /**
     * @param  ReliabilityMetric  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $this->periodTypeLabel($row->period_type),
            $this->dateTime($row->period_start),
            $this->dateTime($row->period_end),
            $row->total_checks,
            $row->successful_checks,
            $row->failed_checks,
            $row->incidents_count,
            $row->uptime_minutes,
            $row->downtime_minutes,
            $row->planned_maintenance_minutes,
            $row->observation_minutes,
            $row->availability_percent,
            $row->mtbf_minutes,
            $row->mttr_minutes,
            $row->failure_rate,
            $this->dateTime($row->calculated_at),
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle ?? __('monitoring.report_types.reliability_metrics');
    }
}
