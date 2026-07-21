<?php

namespace App\Exports\Reports;

use App\Exports\Reports\Sheets\MinitabBucketedChecksSheet;
use App\Exports\Reports\Sheets\MinitabControlChartSummarySheet;
use App\Exports\Reports\Sheets\MinitabOutOfControlPointsSheet;
use App\Exports\Reports\Sheets\MinitabRawChecksSheet;
use App\Exports\Reports\Sheets\MinitabReliabilityMetricsSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MinitabReadyExport implements WithMultipleSheets
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
            new MinitabRawChecksSheet($this->filters),
            new MinitabBucketedChecksSheet($this->filters),
            new MinitabControlChartSummarySheet($this->filters),
            new MinitabOutOfControlPointsSheet($this->filters),
            new MinitabReliabilityMetricsSheet($this->filters),
        ];
    }
}
