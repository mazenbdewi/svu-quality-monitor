<?php

namespace App\Services;

use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ControlChartCalculator
{
    public const CHART_TYPES = [
        'i_chart',
        'mr_chart',
        'p_chart',
        'c_chart',
        'u_chart',
    ];

    public function calculate(
        MonitoredService $service,
        string $chartType,
        Carbon $start,
        Carbon $end,
        string $periodType = 'daily',
        string $bucket = 'hourly',
    ): ControlChart {
        if (! in_array($chartType, self::CHART_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported control chart type [{$chartType}].");
        }

        if (! in_array($bucket, ['hourly', 'daily'], true)) {
            throw new InvalidArgumentException("Unsupported control chart bucket [{$bucket}].");
        }

        $periodStart = $start->copy();
        $periodEnd = $end->copy();
        $metricName = $this->metricNameFor($chartType);

        $chart = ControlChart::query()->updateOrCreate(
            [
                'monitored_service_id' => $service->id,
                'chart_type' => $chartType,
                'metric_name' => $metricName,
                'period_type' => $periodType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'calculated_at' => now(),
            ],
        );

        $chart->points()->delete();

        $result = match ($chartType) {
            'i_chart' => $this->calculateIChart($service, $periodStart, $periodEnd),
            'mr_chart' => $this->calculateMrChart($service, $periodStart, $periodEnd),
            'p_chart' => $this->calculatePChart($service, $periodStart, $periodEnd, $bucket),
            'c_chart' => $this->calculateCChart($service, $periodStart, $periodEnd, $bucket),
            'u_chart' => $this->calculateUChart($service, $periodStart, $periodEnd, $bucket),
        };

        $this->storePoints($chart, $result['points']);

        $chart->update([
            'center_line' => $result['center_line'],
            'ucl' => $result['ucl'],
            'lcl' => $result['lcl'],
            'points_count' => count($result['points']),
            'out_of_control_count' => collect($result['points'])
                ->where('is_out_of_control', true)
                ->count(),
            'calculated_at' => now(),
        ]);

        return $chart->refresh();
    }

    /**
     * @return array{center_line: ?float, ucl: ?float, lcl: ?float, points: list<array<string, mixed>>}
     */
    private function calculateIChart(MonitoredService $service, Carbon $start, Carbon $end): array
    {
        $checks = $this->responseTimeChecks($service, $start, $end);
        $values = $checks->pluck('response_time_ms')->map(fn ($value): float => (float) $value)->values();
        $centerLine = $values->isNotEmpty() ? $values->avg() : null;
        $movingRanges = $this->movingRanges($values);
        $mrBar = $movingRanges->isNotEmpty() ? $movingRanges->avg() : null;
        $sigma = $mrBar === null ? null : $mrBar / 1.128;
        $ucl = $centerLine === null || $sigma === null ? null : $centerLine + (3 * $sigma);
        $lcl = $centerLine === null || $sigma === null ? null : max($centerLine - (3 * $sigma), 0);

        $points = $checks->map(fn (ServiceCheck $check): array => $this->point(
            pointTime: $check->checked_at,
            value: (float) $check->response_time_ms,
            centerLine: $centerLine,
            ucl: $ucl,
            lcl: $lcl,
        ))->values()->all();

        return [
            'center_line' => $this->rounded($centerLine),
            'ucl' => $this->rounded($ucl),
            'lcl' => $this->rounded($lcl),
            'points' => $points,
        ];
    }

    /**
     * @return array{center_line: ?float, ucl: ?float, lcl: ?float, points: list<array<string, mixed>>}
     */
    private function calculateMrChart(MonitoredService $service, Carbon $start, Carbon $end): array
    {
        $checks = $this->responseTimeChecks($service, $start, $end)->values();
        $values = $checks->pluck('response_time_ms')->map(fn ($value): float => (float) $value)->values();
        $movingRanges = $this->movingRanges($values);
        $mrBar = $movingRanges->isNotEmpty() ? $movingRanges->avg() : null;
        $ucl = $mrBar === null ? null : 3.267 * $mrBar;
        $lcl = $mrBar === null ? null : 0.0;
        $points = [];

        foreach ($movingRanges as $index => $range) {
            $points[] = $this->point(
                pointTime: $checks[$index + 1]->checked_at,
                value: (float) $range,
                centerLine: $mrBar,
                ucl: $ucl,
                lcl: $lcl,
            );
        }

        return [
            'center_line' => $this->rounded($mrBar),
            'ucl' => $this->rounded($ucl),
            'lcl' => $this->rounded($lcl),
            'points' => $points,
        ];
    }

    /**
     * @return array{center_line: ?float, ucl: ?float, lcl: ?float, points: list<array<string, mixed>>}
     */
    private function calculatePChart(MonitoredService $service, Carbon $start, Carbon $end, string $bucket): array
    {
        $buckets = $this->checkBuckets($service, $start, $end, $bucket);
        $totalChecks = $buckets->sum('total_checks');
        $totalFailed = $buckets->sum('failed_count');
        $pBar = $totalChecks > 0 ? $totalFailed / $totalChecks : null;
        $points = [];

        foreach ($buckets as $bucketData) {
            $sampleSize = $bucketData['total_checks'];
            $value = $sampleSize > 0 ? $bucketData['failed_count'] / $sampleSize : 0.0;
            $variance = $pBar === null || $sampleSize === 0
                ? null
                : sqrt(($pBar * (1 - $pBar)) / $sampleSize);
            $ucl = $variance === null ? null : $pBar + (3 * $variance);
            $lcl = $variance === null ? null : max($pBar - (3 * $variance), 0);

            $points[] = $this->point(
                pointTime: $bucketData['point_time'],
                value: $value,
                centerLine: $pBar,
                ucl: $ucl,
                lcl: $lcl,
                sampleSize: $sampleSize,
                failedCount: $bucketData['failed_count'],
            );
        }

        return [
            'center_line' => $this->rounded($pBar),
            'ucl' => $this->averagePointLimit($points, 'ucl'),
            'lcl' => $this->averagePointLimit($points, 'lcl'),
            'points' => $points,
        ];
    }

    /**
     * @return array{center_line: ?float, ucl: ?float, lcl: ?float, points: list<array<string, mixed>>}
     */
    private function calculateCChart(MonitoredService $service, Carbon $start, Carbon $end, string $bucket): array
    {
        $buckets = $this->checkBuckets($service, $start, $end, $bucket);
        $cBar = $buckets->isNotEmpty() ? $buckets->avg('failed_count') : null;
        $ucl = $cBar === null ? null : $cBar + (3 * sqrt($cBar));
        $lcl = $cBar === null ? null : max($cBar - (3 * sqrt($cBar)), 0);
        $points = $buckets->map(fn (array $bucketData): array => $this->point(
            pointTime: $bucketData['point_time'],
            value: (float) $bucketData['failed_count'],
            centerLine: $cBar,
            ucl: $ucl,
            lcl: $lcl,
            sampleSize: $bucketData['total_checks'],
            failedCount: $bucketData['failed_count'],
        ))->values()->all();

        return [
            'center_line' => $this->rounded($cBar),
            'ucl' => $this->rounded($ucl),
            'lcl' => $this->rounded($lcl),
            'points' => $points,
        ];
    }

    /**
     * @return array{center_line: ?float, ucl: ?float, lcl: ?float, points: list<array<string, mixed>>}
     */
    private function calculateUChart(MonitoredService $service, Carbon $start, Carbon $end, string $bucket): array
    {
        $buckets = $this->checkBuckets($service, $start, $end, $bucket);
        $totalChecks = $buckets->sum('total_checks');
        $totalFailed = $buckets->sum('failed_count');
        $uBar = $totalChecks > 0 ? $totalFailed / $totalChecks : null;
        $points = [];

        foreach ($buckets as $bucketData) {
            $sampleSize = $bucketData['total_checks'];
            $value = $sampleSize > 0 ? $bucketData['failed_count'] / $sampleSize : 0.0;
            $variance = $uBar === null || $sampleSize === 0 ? null : sqrt($uBar / $sampleSize);
            $ucl = $variance === null ? null : $uBar + (3 * $variance);
            $lcl = $variance === null ? null : max($uBar - (3 * $variance), 0);

            $points[] = $this->point(
                pointTime: $bucketData['point_time'],
                value: $value,
                centerLine: $uBar,
                ucl: $ucl,
                lcl: $lcl,
                sampleSize: $sampleSize,
                failedCount: $bucketData['failed_count'],
            );
        }

        return [
            'center_line' => $this->rounded($uBar),
            'ucl' => $this->averagePointLimit($points, 'ucl'),
            'lcl' => $this->averagePointLimit($points, 'lcl'),
            'points' => $points,
        ];
    }

    private function metricNameFor(string $chartType): string
    {
        return match ($chartType) {
            'i_chart', 'mr_chart' => 'response_time_ms',
            'p_chart' => 'failure_proportion',
            'c_chart' => 'failed_checks_count',
            'u_chart' => 'failures_per_check',
            default => throw new InvalidArgumentException("Unsupported control chart type [{$chartType}]."),
        };
    }

    /**
     * @return Collection<int, ServiceCheck>
     */
    private function responseTimeChecks(MonitoredService $service, Carbon $start, Carbon $end): Collection
    {
        return $service->serviceChecks()
            ->whereBetween('checked_at', [$start, $end])
            ->whereIn('check_type', ['http', 'api'])
            ->whereNotNull('response_time_ms')
            ->orderBy('checked_at')
            ->get();
    }

    /**
     * @param  Collection<int, float>  $values
     * @return Collection<int, float>
     */
    private function movingRanges(Collection $values): Collection
    {
        return $values
            ->values()
            ->map(fn (float $value, int $index): ?float => $index === 0
                ? null
                : abs($value - (float) $values[$index - 1]))
            ->filter(fn (?float $value): bool => $value !== null)
            ->values();
    }

    /**
     * @return Collection<int, array{point_time: Carbon, total_checks: int, failed_count: int}>
     */
    private function checkBuckets(MonitoredService $service, Carbon $start, Carbon $end, string $bucket): Collection
    {
        return $service->serviceChecks()
            ->whereBetween('checked_at', [$start, $end])
            ->whereIn('check_type', ['http', 'api'])
            ->orderBy('checked_at')
            ->get()
            ->groupBy(fn (ServiceCheck $check): string => $this->bucketStart($check->checked_at, $bucket)->toDateTimeString())
            ->map(fn (Collection $checks, string $bucketStart): array => [
                'point_time' => Carbon::parse($bucketStart),
                'total_checks' => $checks->count(),
                'failed_count' => $checks
                    ->filter(fn (ServiceCheck $check): bool => ! $check->is_success || $check->is_slow)
                    ->count(),
            ])
            ->sortBy('point_time')
            ->values();
    }

    private function bucketStart(Carbon $checkedAt, string $bucket): Carbon
    {
        return match ($bucket) {
            'daily' => $checkedAt->copy()->startOfDay(),
            default => $checkedAt->copy()->startOfHour(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function point(
        Carbon $pointTime,
        float $value,
        ?float $centerLine,
        ?float $ucl,
        ?float $lcl,
        ?int $sampleSize = null,
        ?int $failedCount = null,
    ): array {
        $signalType = $this->signalType($value, $ucl, $lcl);

        return [
            'point_time' => $pointTime,
            'value' => $this->rounded($value),
            'center_line' => $this->rounded($centerLine),
            'ucl' => $this->rounded($ucl),
            'lcl' => $this->rounded($lcl),
            'sample_size' => $sampleSize,
            'failed_count' => $failedCount,
            'is_out_of_control' => $signalType !== null,
            'signal_type' => $signalType,
        ];
    }

    private function signalType(float $value, ?float $ucl, ?float $lcl): ?string
    {
        if ($ucl !== null && $value > $ucl) {
            return 'above_ucl';
        }

        if ($lcl !== null && $value < $lcl) {
            return 'below_lcl';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $points
     */
    private function averagePointLimit(array $points, string $key): ?float
    {
        $values = collect($points)
            ->pluck($key)
            ->filter(fn ($value): bool => $value !== null);

        return $values->isEmpty() ? null : $this->rounded($values->avg());
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     */
    private function storePoints(ControlChart $chart, array $points): void
    {
        foreach ($points as $point) {
            $chart->points()->create($point);
        }
    }
}
