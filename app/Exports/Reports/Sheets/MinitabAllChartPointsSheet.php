<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ControlChartPoint;
use App\Services\SpcAnalysisWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabAllChartPointsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        return ControlChartPoint::query()
            ->with('controlChart.monitoredService:id,name')
            ->whereHas('controlChart', function (Builder $query): void {
                [$start, $end] = app(SpcAnalysisWindow::class)->exportBounds($this->filters);
                if (! isset($this->filters['chart_id'])) {
                    $query->where('period_start', '<', $end)->where('period_end', '>', $start);
                }
                $query->when($this->filters['chart_id'] ?? null, fn ($q, $id) => $q->whereKey($id));
                $query->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId));
            })
            ->orderBy('control_chart_id')->orderBy('point_time')->orderBy('id');
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
            'point_time',
            'value',
            'center_line',
            'ucl',
            'lcl',
            'sample_size',
            'failed_count',
            'signal_type',
            'chart_id', 'analysis_start', 'analysis_end', 'analysis_timezone', 'aggregation_interval', 'analysis_mode', 'calculation_version', 'data_cutoff', 'signal_flag', 'point_context',
        ];
    }

    /**
     * @param  ControlChartPoint  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->controlChart?->monitoredService?->name,
            $row->controlChart?->chart_type,
            $row->controlChart?->metric_name,
            $this->dateTime($row->point_time),
            $row->value === null ? null : (float) $row->value,
            $row->center_line === null ? null : (float) $row->center_line,
            $row->ucl === null ? null : (float) $row->ucl,
            $row->lcl === null ? null : (float) $row->lcl,
            $row->sample_size,
            $row->failed_count,
            $row->signal_type,
            $row->control_chart_id, $this->dateTime($row->controlChart->period_start), $this->dateTime($row->controlChart->period_end),
            $row->controlChart->analysis_timezone, $row->controlChart->aggregation_interval, $row->controlChart->analysis_mode ?? 'legacy',
            $row->controlChart->calculation_version, $this->dateTime($row->controlChart->data_cutoff), (int) $row->is_out_of_control,
            $row->research_context === null ? null : json_encode($row->research_context, JSON_THROW_ON_ERROR),
        ];
    }

    public function title(): string
    {
        return 'All Chart Points';
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
