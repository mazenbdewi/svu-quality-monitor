<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ReliabilityMetric;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabReliabilityMetricsSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        return ReliabilityMetric::query()
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
            'period_type',
            'period_start',
            'period_end',
            'total_checks',
            'successful_checks',
            'failed_checks',
            'incidents_count',
            'uptime_minutes',
            'downtime_minutes',
            'availability_percent',
            'mtbf_minutes',
            'mttr_minutes',
            'failure_rate',
        ];
    }

    /**
     * @param  ReliabilityMetric  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        return [
            $row->monitoredService?->name,
            $row->period_type,
            $this->dateTime($row->period_start),
            $this->dateTime($row->period_end),
            $row->total_checks,
            $row->successful_checks,
            $row->failed_checks,
            $row->incidents_count,
            $row->uptime_minutes,
            $row->downtime_minutes,
            $row->availability_percent === null ? null : (float) $row->availability_percent,
            $row->mtbf_minutes === null ? null : (float) $row->mtbf_minutes,
            $row->mttr_minutes === null ? null : (float) $row->mttr_minutes,
            $row->failure_rate === null ? null : (float) $row->failure_rate,
        ];
    }

    public function title(): string
    {
        return 'Reliability Metrics';
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
