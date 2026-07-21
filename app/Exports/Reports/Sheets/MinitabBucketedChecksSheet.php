<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabBucketedChecksSheet implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function collection(): Collection
    {
        $bucketSize = ($this->filters['bucket_size'] ?? 'hourly') === 'daily' ? 'daily' : 'hourly';

        return $this->checksQuery()
            ->get()
            ->groupBy(fn (ServiceCheck $check): string => $check->monitored_service_id.'|'.$this->bucketTime($check->checked_at, $bucketSize)->format('Y-m-d H:i:s'))
            ->map(function (Collection $checks) use ($bucketSize): array {
                /** @var ServiceCheck $first */
                $first = $checks->first();
                $bucketTime = $this->bucketTime($first->checked_at, $bucketSize);
                $sampleSize = $checks->count();
                $successfulCount = $checks->filter(fn (ServiceCheck $check): bool => $check->is_success === true && $check->is_slow === false)->count();
                $failedCount = $checks->filter(fn (ServiceCheck $check): bool => $check->is_success === false)->count();
                $slowCount = $checks->filter(fn (ServiceCheck $check): bool => $check->is_slow === true)->count();
                $problematicCount = $checks->filter(fn (ServiceCheck $check): bool => $check->is_success === false || $check->is_slow === true)->count();
                $responseTimes = $checks->pluck('response_time_ms')->filter(fn (mixed $value): bool => $value !== null);

                return [
                    $first->monitoredService?->name,
                    $bucketTime->format('Y-m-d H:i:s'),
                    $bucketTime->format('Y-m-d'),
                    $bucketSize === 'hourly' ? (int) $bucketTime->format('G') : null,
                    $sampleSize,
                    $successfulCount,
                    $failedCount,
                    $slowCount,
                    $problematicCount,
                    $sampleSize > 0 ? $failedCount / $sampleSize : null,
                    $sampleSize > 0 ? $slowCount / $sampleSize : null,
                    $sampleSize > 0 ? $problematicCount / $sampleSize : null,
                    $responseTimes->isEmpty() ? null : $responseTimes->avg(),
                ];
            })
            ->sortBy(fn (array $row): string => (string) $row[0].'|'.(string) $row[1])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'service_name',
            'bucket_time',
            'bucket_date',
            'bucket_hour',
            'sample_size',
            'successful_count',
            'failed_count',
            'slow_count',
            'problematic_count',
            'failure_proportion',
            'slow_proportion',
            'problematic_proportion',
            'average_response_time_ms',
        ];
    }

    public function title(): string
    {
        return 'Bucketed Checks';
    }

    private function checksQuery(): Builder
    {
        return ServiceCheck::query()
            ->with('monitoredService:id,name')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->where('checked_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->where('checked_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderBy('monitored_service_id')
            ->orderBy('checked_at');
    }

    private function bucketTime(mixed $value, string $bucketSize): Carbon
    {
        $date = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);

        return $bucketSize === 'daily'
            ? $date->startOfDay()
            : $date->minute(0)->second(0)->microsecond(0);
    }
}
