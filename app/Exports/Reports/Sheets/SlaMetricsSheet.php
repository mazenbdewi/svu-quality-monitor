<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\SlaMetric;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class SlaMetricsSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        return SlaMetric::query()->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $id) => $query->where('monitored_service_id', $id))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_start', '<=', $date))
            ->orderBy('period_start');
    }

    public function headings(): array
    {
        return ['Service', 'Period', 'Target', 'Actual', 'Eligible Time (s)', 'Planned Maintenance (s)', 'Unplanned Downtime (s)', 'Allowed Downtime (s)', 'Consumed Budget (s)', 'Remaining Budget (s)', 'Budget %', 'Status'];
    }

    public function map($row): array
    {
        return [$row->monitoredService?->name, $row->period_start?->toDateString(), $row->target_percent, $row->availability_percent, $row->eligible_observation_seconds, $row->planned_maintenance_seconds, $row->unplanned_downtime_seconds, $row->allowed_downtime_seconds, $row->error_budget_consumed_seconds, $row->error_budget_remaining_seconds, $row->error_budget_consumed_percent, $row->status];
    }

    public function title(): string
    {
        return 'SLA';
    }
}
