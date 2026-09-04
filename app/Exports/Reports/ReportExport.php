<?php

namespace App\Exports\Reports;

use App\Exports\Reports\Sheets\ControlChartsSheet;
use App\Exports\Reports\Sheets\MaintenanceWindowsSheet;
use App\Exports\Reports\Sheets\ReliabilityMetricsSheet;
use App\Exports\Reports\Sheets\ServiceChecksSheet;
use App\Exports\Reports\Sheets\ServiceIncidentsSheet;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        protected string $reportType,
        protected array $filters = [],
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            match ($this->reportType) {
                'service_checks' => new ServiceChecksSheet($this->filters),
                'incidents' => new ServiceIncidentsSheet($this->filters),
                'reliability_metrics' => new ReliabilityMetricsSheet($this->filters),
                'control_charts' => new ControlChartsSheet($this->filters),
                'maintenance_windows' => new MaintenanceWindowsSheet($this->filters),
                default => throw new InvalidArgumentException("Unsupported report type [{$this->reportType}]."),
            },
        ];
    }
}
