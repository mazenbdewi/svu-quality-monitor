<?php

namespace App\Services\Baselines;

use App\Models\MonitoredService;
use App\Services\MaintenanceWindowService;
use App\Services\ReliabilityTimeIntervals;
use App\Services\SpcResearchData;
use Carbon\Carbon;

class BaselinePopulation
{
    public function __construct(private SpcResearchData $research, private MaintenanceWindowService $maintenance, private ReliabilityTimeIntervals $intervals) {}

    public function capture(MonitoredService $service, string $type, Carbon $start, Carbon $cutoff, string $aggregation, string $timezone): array
    {
        $candidates = $service->serviceChecks()->where('checked_at', '>=', $start)->where('checked_at', '<', $cutoff)
            ->orderBy('checked_at')->orderBy('id')->sharedLock()->get();
        $binary = $this->research->checks($service, $start, $cutoff)->keyBy('id');
        $latency = $this->research->checks($service, $start, $cutoff, true)->keyBy('id');
        $maintenance = $this->maintenance->mergedOverlapIntervals($service, $start, $cutoff);
        $samples = [];
        $excluded = [];
        foreach ($candidates as $index => $check) {
            $late = $check->created_at === null || $check->created_at->gt($cutoff);
            $modifiedLate = $check->updated_at !== null && $check->updated_at->gt($cutoff);
            $binaryEligible = $binary->has($check->id) && ! $late && ! $modifiedLate;
            $included = ($type === 'p_chart' ? $binary->has($check->id) : $latency->has($check->id)) && ! $late && ! $modifiedLate;
            $reasons = [];
            if ($check->source !== 'automatic') {
                $reasons[] = 'manual_or_unknown_source';
            }
            if ($check->is_during_maintenance || $maintenance->contains(fn ($w) => $check->checked_at->gte($w['start']) && $check->checked_at->lt($w['end']))) {
                $reasons[] = 'maintenance';
            }
            if (data_get($check->metadata, 'is_synthetic') || data_get($check->metadata, 'is_diagnostic')) {
                $reasons[] = 'diagnostic_or_synthetic';
            }
            if (! in_array($check->check_type, ['http', 'api'], true)) {
                $reasons[] = 'type';
            }
            if ($type !== 'p_chart' && ! $check->is_success) {
                $reasons[] = 'functional_failure';
            }
            if ($type !== 'p_chart' && ($check->response_time_ms === null || $check->response_time_ms < 0)) {
                $reasons[] = 'invalid_latency';
            }
            if ($late) {
                $reasons[] = 'recorded_after_cutoff_or_unknown';
            }
            if ($modifiedLate) {
                $reasons[] = 'modified_after_cutoff';
            }
            if (! $included && $reasons === []) {
                $reasons[] = 'eligibility_policy';
            }
            if ($included) {
                $reasons = [];
            }
            foreach ($reasons as $reason) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            }
            $samples[] = [
                'service_check_id' => $check->id, 'sequence' => $index, 'included' => $included, 'exclusion_reasons' => $reasons,
                'measurement' => [
                    'checked_at' => $check->checked_at->copy()->utc()->toIso8601String(),
                    'recorded_at' => $check->created_at?->copy()->utc()->toIso8601String(),
                    'modified_at' => $check->updated_at?->copy()->utc()->toIso8601String(),
                    'is_synthetic' => (bool) data_get($check->metadata, 'is_synthetic', false),
                    'is_diagnostic' => (bool) data_get($check->metadata, 'is_diagnostic', false),
                    'source' => $check->source, 'check_type' => $check->check_type,
                    'response_time_ms' => $check->response_time_ms === null ? null : (float) $check->response_time_ms,
                    'is_success' => (bool) $check->is_success, 'is_slow' => (bool) $check->is_slow,
                    'problematic' => (bool) $check->is_problematic, 'performance_status' => $check->performance_status,
                    'is_during_maintenance' => (bool) $check->is_during_maintenance, 'binary_eligible' => $binaryEligible,
                ],
            ];
        }
        // Reuse R3 calendar/expected-opportunity policy, but replace observed counts with frozen, cutoff-eligible samples.
        $buckets = $this->research->buckets($service, $start, $cutoff, $aggregation, $timezone)->all();
        $interval = max(1, (int) $service->check_interval_minutes) * 60;
        $groups = collect($samples)->filter(fn ($s) => $s['measurement']['binary_eligible'])->groupBy(function ($sample) use ($aggregation, $timezone) {
            $at = Carbon::parse($sample['measurement']['checked_at'])->setTimezone($timezone);

            return ($aggregation === 'daily' ? $at->startOfDay() : $at->startOfHour())->utc()->toIso8601String();
        });
        foreach ($buckets as &$bucket) {
            $a = Carbon::parse($bucket['bucket_start']);
            $b = Carbon::parse($bucket['bucket_end']);
            $eligibleStart = $a->copy()->max($service->created_at);
            $segments = $b->gt($eligibleStart) ? $this->intervals->subtract(collect([['start' => $eligibleStart, 'end' => $b]]), $maintenance) : collect();
            $key = $a->copy()->setTimezone($timezone);
            $key = ($aggregation === 'daily' ? $key->startOfDay() : $key->startOfHour())->utc()->toIso8601String();
            $group = $groups->get($key, collect())->all();
            $slots = [];
            foreach ($group as $sample) {
                $at = Carbon::parse($sample['measurement']['checked_at']);
                $offset = 0;
                foreach ($segments as $segment) {
                    if ($at->gte($segment['start']) && $at->lt($segment['end'])) {
                        $slots[(int) floor(($offset + $segment['start']->diffInSeconds($at)) / $interval)] = true;
                        break;
                    }
                    $offset += $segment['start']->diffInSeconds($segment['end']);
                }
            }
            $bucket['observed_count'] = count($group);
            $bucket['covered_count'] = count($slots);
            $bucket['missing_count'] = max(0, $bucket['expected_count'] - count($slots));
            $bucket['coverage'] = $bucket['expected_count'] ? 100 * count($slots) / $bucket['expected_count'] : null;
            $bucket['problematic_count'] = array_sum(array_map(fn ($s) => (int) $s['measurement']['problematic'], $group));
            $bucket['failed_count'] = count(array_filter($group, fn ($s) => ! $s['measurement']['is_success']));
            $bucket['problematic_proportion'] = count($group) ? $bucket['problematic_count'] / count($group) : null;
            $bucket['status'] = count($group) === 0 ? ($bucket['expected_count'] ? 'missing' : 'not_expected') : ($bucket['missing_count'] ? 'partially_observed' : 'observed');
        }
        unset($bucket);
        $expected = array_sum(array_column($buckets, 'expected_count'));
        $covered = array_sum(array_column($buckets, 'covered_count'));

        return [
            'samples' => $samples,
            'counts' => ['candidates' => count($samples), 'included' => count(array_filter($samples, fn ($s) => $s['included'])), 'excluded' => count(array_filter($samples, fn ($s) => ! $s['included'])), 'exclusion_counts' => $excluded],
            'coverage' => ['status' => $covered === 0 ? 'no_data' : ($covered < $expected ? 'insufficient_coverage' : 'observed'), 'policy' => 'r3-research-slots+record-cutoff-v1', 'expected_count' => $expected, 'covered_count' => $covered, 'missing_count' => $expected - $covered, 'coverage_percent' => $expected ? 100 * $covered / $expected : null, 'aggregation' => $aggregation, 'interval_seconds' => $interval, 'buckets' => $buckets],
        ];
    }
}
