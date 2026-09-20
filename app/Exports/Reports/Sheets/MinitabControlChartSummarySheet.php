<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ControlChart;
use App\Services\SpcAnalysisWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabControlChartSummarySheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        [$start, $end] = app(SpcAnalysisWindow::class)->exportBounds($this->filters);

        return ControlChart::query()->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn ($q, $id) => $q->where('monitored_service_id', $id))
            ->when($this->filters['chart_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('period_start', '<', $end)->where('period_end', '>', $start))
            ->orderBy('period_start')->orderBy('id');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'service_name',
            'chart_type',
            'metric_name',
            'period_type',
            'period_start',
            'period_end',
            'center_line',
            'ucl',
            'lcl',
            'points_count',
            'out_of_control_count',
            'chart_id', 'analysis_timezone', 'aggregation_interval', 'analysis_mode', 'data_cutoff', 'calculation_version', 'research_context',
        ];
    }

    /**
     * @param  ControlChart  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $row->chart_type,
            $row->metric_name,
            $row->period_type,
            $this->dateTime($row->period_start),
            $this->dateTime($row->period_end),
            $row->center_line === null ? null : (float) $row->center_line,
            $row->ucl === null ? null : (float) $row->ucl,
            $row->lcl === null ? null : (float) $row->lcl,
            $row->points_count,
            $row->out_of_control_count,
            $row->id, $row->analysis_timezone, $row->aggregation_interval, $row->analysis_mode ?? 'legacy',
            $this->dateTime($row->data_cutoff), $row->calculation_version,
            $row->research_context === null ? null : json_encode(Arr::except($row->research_context, ['buckets']), JSON_THROW_ON_ERROR),
        ];
    }

    public function title(): string
    {
        return 'Control Chart Summary';
    }

    private function dateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('Y-m-d H:i:s');
    }
}
