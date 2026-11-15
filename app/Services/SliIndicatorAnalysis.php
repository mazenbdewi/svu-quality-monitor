<?php

namespace App\Services;

use App\Models\MonitoredService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Descriptive, read-only SLIs for one completed UTC half-open window. */
class SliIndicatorAnalysis
{
    public function __construct(private EligibleSyntheticChecks $eligibility) {}

    /** @return array<string, int|float|null> */
    public function describe(MonitoredService $service, Carbon $start, Carbon $end, ?int $latencyObjectiveMs): array
    {
        if (! in_array($service->check_type->value, ['http', 'api'], true)
            || $start->gte($end) || $end->gt(now()->utc()) || ($latencyObjectiveMs !== null && $latencyObjectiveMs < 0)) {
            throw new InvalidArgumentException('SLI analysis requires an HTTP/API service, completed period, and nonnegative objective.');
        }

        return DB::transaction(function () use ($service, $start, $end, $latencyObjectiveMs): array {
            $maintenance = $this->eligibility->maintenance($service, $start, $end);
            $query = $this->eligibility->query($service, $start, $end, $maintenance);
            $summary = $query->toBase()->selectRaw(
                'COUNT(*) AS executed_checks, '.
                'SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) AS successful_checks, '.
                'SUM(CASE WHEN is_success = 0 THEN 1 ELSE 0 END) AS failed_checks, '.
                'SUM(CASE WHEN is_success = 1 AND (response_time_ms IS NULL OR response_time_ms < 0) THEN 1 ELSE 0 END) AS successful_without_valid_latency, '.
                'SUM(CASE WHEN is_success = 1 AND response_time_ms IS NOT NULL AND response_time_ms >= 0 THEN 1 ELSE 0 END) AS successful_with_valid_latency, '.
                'SUM(CASE WHEN is_success = 1 AND response_time_ms IS NOT NULL AND response_time_ms >= 0 AND response_time_ms <= ? THEN 1 ELSE 0 END) AS successful_within_objective',
                [$latencyObjectiveMs ?? -1]
            )->first();
            $executed = (int) $summary->executed_checks;
            $success = (int) $summary->successful_checks;
            $validLatency = (int) $summary->successful_with_valid_latency;
            $within = $latencyObjectiveMs === null ? null : (int) $summary->successful_within_objective;

            return [
                'executed_checks' => $executed,
                'successful_checks' => $success,
                'failed_checks' => (int) $summary->failed_checks,
                'successful_without_valid_latency' => (int) $summary->successful_without_valid_latency,
                'successful_with_valid_latency' => $validLatency,
                'successful_within_objective' => $within,
                'availability_percent' => $executed ? (float) (100 * $success / $executed) : null,
                'conditional_successful_latency_percent' => $validLatency && $within !== null
                    ? (float) (100 * $within / $validLatency) : null,
                'combined_latency_success_percent' => $executed && $within !== null ? (float) (100 * $within / $executed) : null,
            ];
        });
    }
}
