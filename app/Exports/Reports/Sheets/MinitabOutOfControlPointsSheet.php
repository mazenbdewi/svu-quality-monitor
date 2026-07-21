<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ControlChartPoint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabOutOfControlPointsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
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
            ->where('is_out_of_control', true)
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->where('point_time', '>=', Carbon::parse($date)->startOfDay()))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->where('point_time', '<=', Carbon::parse($date)->endOfDay()))
            ->whereHas('controlChart', function (Builder $query): void {
                $query->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId));
            })
            ->orderBy('point_time');
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
        ];
    }

    public function title(): string
    {
        return 'Out Of Control Points';
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
