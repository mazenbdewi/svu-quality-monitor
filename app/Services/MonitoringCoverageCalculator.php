<?php

namespace App\Services;

use App\Models\MonitoredService;
use Carbon\Carbon;
use InvalidArgumentException;

/** Read-only completeness estimate, never an availability or health classification. */
class MonitoringCoverageCalculator
{
    public function __construct(private MaintenanceWindowService $maintenance) {}

    public function calculate(MonitoredService $service, Carbon $from, Carbon $to): array
    {
        if ($to->lte($from) || (int) $service->check_interval_minutes < 1) {
            throw new InvalidArgumentException('Coverage requires a positive period and monitoring interval.');
        }

        $end = $to->copy()->utc()->min(now()->utc());
        $first = $service->serviceChecks()->automaticObservations()
            ->where('checked_at', '<', $end)->min('checked_at');
        $start = $from->copy()->utc();
        if ($first !== null) {
            $start = $start->max(Carbon::parse($first, 'UTC'));
        }
        $result = [
            'period_start' => $from->copy()->utc()->toIso8601String(),
            'period_end' => $to->copy()->utc()->toIso8601String(),
            'data_cutoff' => $end->toIso8601String(),
            'first_observed_at' => $first,
            'effective_start' => $first === null ? null : $start->toIso8601String(),
            'interval_seconds' => (int) $service->check_interval_minutes * 60,
            'eligible_seconds' => 0,
            'planned_maintenance_seconds' => 0,
            'expected_automatic_checks' => 0,
            'observed_automatic_checks' => 0,
            'covered_intervals' => 0,
            'missing_checks' => 0,
            'coverage_percent' => null,
            'status' => 'no_data',
        ];
        if ($first === null || $start->gte($end)) {
            return $result;
        }

        $windows = $this->maintenance->mergedOverlapIntervals($service, $start, $end);
        $segments = [];
        $cursor = $start->copy();
        foreach ($windows as $window) {
            if ($cursor->lt($window['start'])) {
                $segments[] = [$cursor->copy(), $window['start']->copy()];
            }
            $cursor = $window['end']->copy();
        }
        if ($cursor->lt($end)) {
            $segments[] = [$cursor, $end];
        }
        $eligible = array_sum(array_map(fn (array $segment): float => $segment[0]->diffInSeconds($segment[1]), $segments));
        $result['eligible_seconds'] = $eligible;
        $result['planned_maintenance_seconds'] = $start->diffInSeconds($end) - $eligible;
        $expected = (int) ceil($eligible / $result['interval_seconds']);
        $result['expected_automatic_checks'] = $expected;

        $checks = $service->serviceChecks()->automaticObservations()
            ->where('is_during_maintenance', false)
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->orderBy('checked_at')->get(['checked_at']);
        $occupied = [];
        foreach ($checks as $check) {
            $offset = 0;
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                if ($check->checked_at->gte($segmentStart) && $check->checked_at->lt($segmentEnd)) {
                    $slot = (int) floor(($offset + $segmentStart->diffInSeconds($check->checked_at)) / $result['interval_seconds']);
                    $occupied[$slot] = true;
                    $result['observed_automatic_checks']++;
                    break;
                }
                $offset += $segmentStart->diffInSeconds($segmentEnd);
            }
        }
        $covered = count($occupied);
        $result['covered_intervals'] = $covered;
        $result['missing_checks'] = max(0, $expected - $covered);
        $result['coverage_percent'] = $expected > 0 ? round(100 * $covered / $expected, 2) : null;
        $result['status'] = $covered === 0 ? 'no_data' : ($covered < $expected ? 'insufficient_coverage' : 'observed');

        return $result;
    }
}
