<?php

namespace App\Services;

use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ControlChartCalculator
{
    public const RESEARCH_TYPES = ['i_chart', 'mr_chart', 'p_chart'];

    public function __construct(private SpcResearchData $research, private SpcAnalysisWindow $windows) {}

    public function analyze(MonitoredService $service, string $chartType, string $window = '30', ?string $from = null, ?string $to = null, string $aggregation = 'hourly'): ControlChart
    {
        [$start, $end] = $this->windows->resolve($window, $from, $to);

        return $this->calculate($service, $chartType, $start, $end, $window === 'custom' ? 'custom' : $window.'_days', $aggregation);
    }

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
        ?Carbon $dataCutoff = null,
    ): ControlChart {
        if (! in_array($chartType, self::CHART_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported control chart type [{$chartType}].");
        }

        if (! in_array($bucket, ['hourly', 'daily'], true)) {
            throw new InvalidArgumentException("Unsupported control chart bucket [{$bucket}].");
        }

        if ($end->lte($start)) {
            throw new InvalidArgumentException('SPC requires a positive half-open analysis window.');
        }
        $periodStart = $start->copy()->utc();
        $periodEnd = $end->copy()->utc();
        $cutoff = $periodEnd->copy()->min(now()->utc());
        if ($dataCutoff !== null) {
            $cutoff = $cutoff->min($dataCutoff->copy()->utc());
        }
        $timezone = $this->windows->timezone();
        $aggregation = in_array($chartType, ['i_chart', 'mr_chart'], true) ? 'raw' : $bucket;
        // Raw I/MR identity must not acquire different coverage semantics from a P bucket option.
        $bucket = $aggregation === 'raw' ? 'hourly' : $bucket;
        $core = in_array($chartType, self::RESEARCH_TYPES, true);
        $mode = $core ? 'exploratory' : 'legacy';
        $identity = hash('sha256', json_encode([$service->id, $chartType, $periodStart->toIso8601String(), $periodEnd->toIso8601String(), $aggregation, $mode, $timezone]));

        return DB::transaction(function () use ($service, $chartType, $periodType, $periodStart, $periodEnd, $cutoff, $timezone, $aggregation, $bucket, $core, $mode, $identity) {
            $result = match ($chartType) {
                'i_chart' => $this->calculateIChart($service, $periodStart, $cutoff),
                'mr_chart' => $this->calculateMrChart($service, $periodStart, $cutoff),
                'p_chart' => $this->calculatePChart($service, $periodStart, $cutoff, $bucket),
                'c_chart' => $this->calculateCChart($service, $periodStart, $cutoff, $bucket),
                'u_chart' => $this->calculateUChart($service, $periodStart, $cutoff, $bucket),
            };
            $buckets = $core ? $this->research->buckets($service, $periodStart, $cutoff, $bucket, $timezone) : collect();
            $sample = $core ? $this->research->checks($service, $periodStart, $cutoff, $chartType !== 'p_chart') : collect();
            $count = count($result['points']);
            $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
            $sufficiency = $sample->isEmpty() ? 'no_data' : ($count < 2 ? 'insufficient' : ($count < $minimum || $buckets->sum('missing_count') > 0 ? 'preliminary' : 'analyzable'));
            $expected = $buckets->sum('expected_count');
            $chart = ControlChart::query()->updateOrCreate(['analysis_identity' => $identity], [
                'monitored_service_id' => $service->id, 'chart_type' => $chartType,
                'metric_name' => $this->metricNameFor($chartType), 'period_type' => $periodType,
                'period_start' => $periodStart, 'period_end' => $periodEnd,
                'analysis_timezone' => $timezone, 'aggregation_interval' => $aggregation,
                'analysis_mode' => $mode, 'calculation_version' => 'spc-r3-v1', 'data_cutoff' => $cutoff,
                'research_context' => [
                    'sufficiency' => $core ? $sufficiency : 'legacy', 'sample_size' => $sample->count(),
                    'partial' => $cutoff->lt($periodEnd), 'limits_basis' => 'estimated_from_analysis_window',
                    'exploratory_min_points_policy' => $minimum,
                    'coverage' => $expected > 0 ? 100 * $buckets->sum('covered_count') / $expected : null,
                    'coverage_aggregation' => $bucket, 'expected_count' => $expected, 'observed_count' => $buckets->sum('observed_count'),
                    'missing_count' => $buckets->sum('missing_count'), 'buckets' => $buckets->all(),
                ],
                'center_line' => $result['center_line'], 'ucl' => $result['ucl'], 'lcl' => $result['lcl'],
                'points_count' => $count, 'out_of_control_count' => collect($result['points'])->where('is_out_of_control', true)->count(),
                'calculated_at' => now(),
            ]);
            $chart->points()->delete();
            $this->storePoints($chart, $result['points']);

            return $chart->refresh();
        });
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
        ) + ['research_context' => ['check_id' => $check->id]])->values()->all();

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
            ) + ['research_context' => ['check_id' => $checks[$index + 1]->id, 'previous_check_id' => $checks[$index]->id]];
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
        $buckets = $this->research->buckets($service, $start, $end, $bucket, $this->windows->timezone());
        $total = $buckets->sum('observed_count');
        $pBar = $total > 0 ? $buckets->sum('problematic_count') / $total : null;
        $points = [];
        foreach ($buckets as $group) {
            $n = $group['observed_count'];
            if ($n === 0) {
                continue; // Unknown subgroup remains in chart metadata, never p=0.
            }
            $sigma = sqrt($pBar * (1 - $pBar) / $n);
            $points[] = $this->point(Carbon::parse($group['bucket_start']), $group['problematic_proportion'], $pBar,
                min(1, $pBar + 3 * $sigma), max(0, $pBar - 3 * $sigma), $n, $group['failed_count'])
                + ['research_context' => $group];
        }

        // Variable-n limits belong to points; no misleading average chart limits.
        return ['center_line' => $this->rounded($pBar), 'ucl' => null, 'lcl' => null, 'points' => $points];
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
            'p_chart' => 'problematic_proportion',
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
        return $this->research->checks($service, $start, $end, true);
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
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
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
