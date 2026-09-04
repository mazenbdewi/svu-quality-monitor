<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MaintenanceWindowsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;
    use FormatsReportValues;

    /** @param array<string, mixed> $filters */
    public function __construct(protected array $filters = [], protected ?string $sheetTitle = null) {}

    public function query(): Builder
    {
        return MaintenanceWindow::query()->with('monitoredServices:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $id): Builder => $query->where(fn (Builder $windows) => $windows
                ->where('applies_to_all_services', true)
                ->orWhereHas('monitoredServices', fn (Builder $services) => $services->whereKey($id))))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('ends_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('starts_at', '<=', $date))
            ->orderBy('starts_at');
    }

    public function headings(): array
    {
        return [__('monitoring.report_columns.maintenance_name'), __('monitoring.report_columns.services'), __('monitoring.report_columns.started_at'), __('monitoring.report_columns.ended_at'), __('monitoring.report_columns.duration_minutes'), __('monitoring.report_columns.status')];
    }

    /** @param MaintenanceWindow $row */
    public function map($row): array
    {
        return [$row->name, $row->applies_to_all_services ? __('monitoring.maintenance.all_services') : $row->monitoredServices->pluck('name')->join(', '), $this->dateTime($row->starts_at), $this->dateTime($row->ends_at), $row->durationMinutes(), __('monitoring.maintenance.statuses.'.$row->statusAt())];
    }

    public function title(): string
    {
        return $this->sheetTitle ?? __('monitoring.report_types.maintenance_windows');
    }
}
