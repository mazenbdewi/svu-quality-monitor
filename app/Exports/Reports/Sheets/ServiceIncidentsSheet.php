<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ServiceIncident;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class ServiceIncidentsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
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
        return ServiceIncident::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date))
            ->when($this->filters['status'] ?? null, fn (Builder $query, $status): Builder => $query->where('status', $status))
            ->when($this->filters['severity'] ?? null, fn (Builder $query, $severity): Builder => $query->where('severity', $severity))
            ->when($this->filters['incident_type'] ?? null, fn (Builder $query, $type): Builder => $query->where('incident_type', $type))
            ->orderBy('started_at');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            __('monitoring.report_columns.service_name'),
            __('monitoring.report_columns.status'),
            __('monitoring.report_columns.incident_type'),
            __('monitoring.report_columns.severity'),
            __('monitoring.report_columns.started_at'),
            __('monitoring.report_columns.ended_at'),
            __('monitoring.report_columns.duration_minutes'),
            __('monitoring.report_columns.root_cause'),
            __('monitoring.report_columns.corrective_action'),
            __('monitoring.report_columns.notes'),
        ];
    }

    /**
     * @param  ServiceIncident  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $this->incidentStatusLabel($row->status),
            $this->incidentTypeLabel($row->incident_type),
            $this->severityLabel($row->severity),
            $this->dateTime($row->started_at),
            $this->dateTime($row->ended_at),
            $row->duration_minutes,
            $row->root_cause,
            $row->corrective_action,
            $row->notes,
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle ?? __('monitoring.report_types.incidents');
    }
}
