<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ServiceCheck;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class ServiceChecksSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
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
        return ServiceCheck::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '<=', $date))
            ->when(($this->filters['status'] ?? null) === 'success', fn (Builder $query): Builder => $query->where('is_success', true))
            ->when(($this->filters['status'] ?? null) === 'failed', fn (Builder $query): Builder => $query->where('is_success', false))
            ->when(($this->filters['slow_status'] ?? null) === 'slow', fn (Builder $query): Builder => $query->where('is_slow', true))
            ->when(($this->filters['slow_status'] ?? null) === 'not_slow', fn (Builder $query): Builder => $query->where('is_slow', false))
            ->orderBy('checked_at');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            __('monitoring.report_columns.service_name'),
            __('monitoring.report_columns.checked_at'),
            __('monitoring.report_columns.status_code'),
            __('monitoring.report_columns.response_time_ms'),
            __('monitoring.report_columns.is_success'),
            __('monitoring.report_columns.is_slow'),
            __('monitoring.report_columns.is_during_maintenance'),
            __('monitoring.report_columns.error_type'),
            __('monitoring.report_columns.error_message'),
            __('monitoring.report_columns.expected_keyword_found'),
        ];
    }

    /**
     * @param  ServiceCheck  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $this->dateTime($row->checked_at),
            $row->status_code,
            $row->response_time_ms,
            $this->boolLabel($row->is_success),
            $this->boolLabel($row->is_slow),
            $this->boolLabel($row->is_during_maintenance),
            $this->problemTypeLabel($row->error_type),
            $row->error_message,
            $this->boolLabel($row->expected_keyword_found),
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle ?? __('monitoring.report_types.service_checks');
    }
}
