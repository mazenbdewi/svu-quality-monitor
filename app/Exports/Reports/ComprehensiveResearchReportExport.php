<?php

namespace App\Exports\Reports;

use App\Exports\Reports\Sheets\ControlChartsSheet;
use App\Exports\Reports\Sheets\MaintenanceWindowsSheet;
use App\Exports\Reports\Sheets\OutOfControlPointsSheet;
use App\Exports\Reports\Sheets\ReliabilityMetricsSheet;
use App\Exports\Reports\Sheets\ServiceChecksSheet;
use App\Exports\Reports\Sheets\ServiceIncidentsSheet;
use App\Exports\Reports\Sheets\SummarySheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ComprehensiveResearchReportExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new SummarySheet($this->filters),
            new ServiceChecksSheet($this->filters, __('monitoring.reports.sheets.service_checks')),
            new ServiceIncidentsSheet($this->filters, __('monitoring.reports.sheets.incidents')),
            new ReliabilityMetricsSheet($this->filters, __('monitoring.reports.sheets.reliability_metrics')),
            new MaintenanceWindowsSheet($this->filters, __('monitoring.report_types.maintenance_windows')),
            new ControlChartsSheet($this->filters, __('monitoring.reports.sheets.control_charts')),
            new OutOfControlPointsSheet($this->filters),
        ];
    }
}
