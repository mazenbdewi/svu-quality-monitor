<?php

namespace App\Exports\Reports\Sheets;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Persisted buckets, including gaps; one row per bucket avoids oversized Excel JSON cells. */
class MinitabChartBucketsSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function collection(): Collection
    {
        return (new MinitabControlChartSummarySheet($this->filters))->query()->get()->flatMap(fn ($chart) => collect(data_get($chart->research_context, 'buckets', []))->map(fn ($b) => [
            $chart->id, $chart->chart_type, $chart->analysis_timezone, $chart->aggregation_interval,
            $b['bucket_start'], $b['bucket_end'], $b['expected_count'], $b['observed_count'],
            $b['missing_count'], $b['coverage'], $b['problematic_count'], $b['problematic_proportion'], $b['status'],
        ]))->values();
    }

    public function headings(): array
    {
        return ['chart_id', 'chart_type', 'analysis_timezone', 'aggregation_interval', 'bucket_start', 'bucket_end', 'expected_count', 'observed_count', 'missing_count', 'coverage', 'problematic_count', 'problematic_proportion', 'status'];
    }

    public function title(): string
    {
        return 'Stored Chart Buckets';
    }
}
