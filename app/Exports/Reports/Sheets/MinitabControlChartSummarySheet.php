<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ControlChart;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
        return ControlChart::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->where('period_start', '>=', Carbon::parse($date)->startOfDay()))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->where('period_start', '<=', Carbon::parse($date)->endOfDay()))
            ->orderBy('period_start');
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
