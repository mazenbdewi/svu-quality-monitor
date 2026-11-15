<?php

namespace App\Services;

use App\Models\MonitoredService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only, fixed-window synthetic HTTP/API latency analysis. */
class LatencyPercentileAnalysis
{
    public const METHOD = 'linear-interpolation-r-type-7-v1';

    public function __construct(
        private InstitutionTimezone $institutionTimezone,
        private EligibleSyntheticChecks $eligibility,
        private ReliabilityTimeIntervals $intervals,
    ) {}

    /** The window is [local midnight 28 days ago, local midnight today), in UTC. */
    public function window(): array
    {
        $timezone = $this->institutionTimezone->name();
        $localEnd = now($timezone)->startOfDay();

        return [
            'start' => $localEnd->copy()->subDays(28)->utc(),
            'end' => $localEnd->copy()->utc(),
            'timezone' => $timezone,
        ];
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function analyzeAll(): \Generator
    {
        $window = $this->window();
        foreach (MonitoredService::query()->whereIn('check_type', ['http', 'api'])->lazyById() as $service) {
            yield $this->analyzeWindow($service, $window);
        }
    }

    /** @return array<string, mixed> */
    public function analyze(MonitoredService $service): array
    {
        return $this->analyzeWindow($service, $this->window());
    }

    /** Coverage only; reuses the same eligible cohort and current-cadence policy. */
    public function coverageForPeriod(MonitoredService $service, Carbon $start, Carbon $end): array
    {
        $maintenance = $this->eligibility->maintenance($service, $start, $end);

        return $this->coverage($this->eligibility->query($service, $start, $end, $maintenance), $service, $start, $end, $maintenance);
    }

    private function analyzeWindow(MonitoredService $service, array $window): array
    {
        if (! in_array($service->check_type->value, ['http', 'api'], true)) {
            throw new InvalidArgumentException('Latency percentile analysis supports HTTP/API services only.');
        }
        $start = $window['start']->copy()->utc();
        $end = $window['end']->copy()->utc();
        if ($start->gte($end) || $end->gt(now()->utc())) {
            throw new InvalidArgumentException('Analysis requires a completed UTC half-open period.');
        }

        // Keep the aggregate, rank selection, and coverage on one database
        // snapshot while new monitoring records may be arriving concurrently.
        return DB::transaction(fn (): array => $this->analyzeSnapshot($service, $window, $start, $end));
    }

    private function analyzeSnapshot(MonitoredService $service, array $window, Carbon $start, Carbon $end): array
    {
        $maintenance = $this->eligibility->maintenance($service, $start, $end);
        $checks = $this->eligibility->query($service, $start, $end, $maintenance);
        $summary = (clone $checks)->toBase()->selectRaw(
            'COUNT(*) AS eligible_checks, '.
            'SUM(CASE WHEN is_success = 0 THEN 1 ELSE 0 END) AS failed_checks, '.
            'SUM(CASE WHEN is_success = 1 AND (response_time_ms IS NULL OR response_time_ms < 0) THEN 1 ELSE 0 END) AS successful_without_valid_latency, '.
            'SUM(CASE WHEN is_success = 1 AND response_time_ms >= 0 THEN 1 ELSE 0 END) AS latency_observations, '.
            'MIN(CASE WHEN is_success = 1 AND response_time_ms >= 0 THEN response_time_ms END) AS minimum_ms, '.
            'MAX(CASE WHEN is_success = 1 AND response_time_ms >= 0 THEN response_time_ms END) AS maximum_ms, '.
            'AVG(CASE WHEN is_success = 1 AND response_time_ms >= 0 THEN response_time_ms END) AS mean_ms'
        )->first();
        $count = (int) $summary->latency_observations;
        $percentiles = $count ? $this->percentiles($checks, $count) : ['p50' => null, 'p90' => null, 'p95' => null, 'p99' => null];
        $coverage = $this->coverage($checks, $service, $start, $end, $maintenance);
        $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));

        return [
            'service_id' => $service->id,
            'service_name' => $service->name,
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'boundary' => '[start,end)',
            'data_cutoff' => $end->toIso8601String(),
            'analysis_timezone' => $window['timezone'],
            'method' => self::METHOD,
            'latency_observations' => $count,
            'p50_ms' => $percentiles['p50'],
            'p90_ms' => $percentiles['p90'],
            'p95_ms' => $percentiles['p95'],
            'p99_ms' => $percentiles['p99'],
            'minimum_ms' => $summary->minimum_ms === null ? null : (int) $summary->minimum_ms,
            'maximum_ms' => $summary->maximum_ms === null ? null : (int) $summary->maximum_ms,
            'mean_ms' => $summary->mean_ms === null ? null : (float) $summary->mean_ms,
            'eligible_checks' => (int) $summary->eligible_checks,
            'failed_checks' => (int) $summary->failed_checks,
            'successful_without_valid_latency' => (int) $summary->successful_without_valid_latency,
            'sample_status' => $count === 0 ? 'no_data' : ($count < $minimum ? 'insufficient_data' : 'analyzable'),
            'minimum_observations_policy' => $minimum,
            'coverage' => $coverage,
            'configuration_warnings' => (int) $service->critical_response_ms < (int) $service->warning_response_ms
                ? ['critical_response_below_warning_response'] : [],
        ];
    }

    /** Exact R type 7 interpolation: h = 1 + (n - 1)p, using 1-based sorted ranks. */
    private function percentiles(Builder $checks, int $count): array
    {
        $positions = [];
        foreach (['p50' => 0.50, 'p90' => 0.90, 'p95' => 0.95, 'p99' => 0.99] as $name => $probability) {
            $h = 1 + ($count - 1) * $probability;
            $positions[$name] = ['lower' => (int) floor($h), 'upper' => (int) ceil($h), 'fraction' => $h - floor($h)];
        }
        $ranks = collect($positions)->flatMap(fn (array $position) => [$position['lower'], $position['upper']])->unique()->values()->all();
        $ordered = (clone $checks)->where('is_success', true)->whereNotNull('response_time_ms')->where('response_time_ms', '>=', 0)
            ->select('response_time_ms')->selectRaw('ROW_NUMBER() OVER (ORDER BY response_time_ms, service_checks.id) AS latency_rank');
        $values = DB::query()->fromSub($ordered->toBase(), 'ranked')
            ->whereIn('latency_rank', $ranks)->pluck('response_time_ms', 'latency_rank');
        $result = [];
        foreach ($positions as $name => $position) {
            $lower = (float) $values->get($position['lower']);
            $upper = (float) $values->get($position['upper']);
            $result[$name] = $lower + $position['fraction'] * ($upper - $lower);
        }

        return $result;
    }

    /** Current-cadence expected slots, bounded by 28 days and streamed in chunks. */
    private function coverage(Builder $checks, MonitoredService $service, Carbon $start, Carbon $end, Collection $maintenance): array
    {
        $eligibleStart = $start->copy()->max($service->created_at);
        $segments = $eligibleStart->lt($end)
            ? $this->intervals->subtract(collect([['start' => $eligibleStart, 'end' => $end]]), $maintenance)
            : collect();
        $interval = max(1, (int) $service->check_interval_minutes) * 60;
        $expected = (int) ceil($this->intervals->seconds($segments) / $interval);
        $occupied = [];
        foreach ((clone $checks)->select('service_checks.id', 'service_checks.checked_at')->lazyById(1000, 'service_checks.id', 'id') as $check) {
            $offset = 0;
            foreach ($segments as $segment) {
                if ($check->checked_at->gte($segment['start']) && $check->checked_at->lt($segment['end'])) {
                    $slot = (int) floor(($offset + $segment['start']->diffInSeconds($check->checked_at)) / $interval);
                    $occupied[$slot] = true;
                    break;
                }
                $offset += $segment['start']->diffInSeconds($segment['end']);
            }
        }
        $covered = count($occupied);

        return [
            'expected_opportunities' => $expected,
            'covered_opportunities' => $covered,
            'missing_opportunities' => max(0, $expected - $covered),
            'coverage_percent' => $expected ? 100 * $covered / $expected : null,
            'status' => $expected === 0 ? 'no_expected_opportunities' : ($covered === 0 ? 'no_data' : ($covered < $expected ? 'insufficient_coverage' : 'observed')),
            'basis' => 'current_service_cadence_and_recorded_maintenance',
        ];
    }
}
