<?php

namespace App\Services;

use App\Models\MonitoredService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Shared calculator/export population; eligibility is owned by the R1 model scopes. */
class SpcResearchData
{
    public function __construct(private MaintenanceWindowService $maintenance, private ReliabilityTimeIntervals $intervals) {}

    public function checks(MonitoredService $service, Carbon $start, Carbon $end, bool $latency = false): Collection
    {
        $query = $service->serviceChecks();
        $latency ? $query->researchResponseTimes() : $query->researchChecks();
        $windows = $end->gt($start) ? $this->maintenance->mergedOverlapIntervals($service, $start, $end) : collect();

        return $query->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->orderBy('checked_at')->orderBy('id')->get()
            ->reject(fn ($check) => $windows->contains(fn ($w) => $check->checked_at->gte($w['start']) && $check->checked_at->lt($w['end'])))->values();
    }

    public function buckets(MonitoredService $service, Carbon $start, Carbon $end, string $aggregation, string $timezone): Collection
    {
        $checks = $this->checks($service, $start, $end);
        $grouped = $checks->groupBy(function ($check) use ($aggregation, $timezone) {
            $at = $check->checked_at->copy()->setTimezone($timezone);

            return ($aggregation === 'daily' ? $at->startOfDay() : $at->startOfHour())->utc()->toIso8601String();
        });
        $maintenance = $end->gt($start) ? $this->maintenance->mergedOverlapIntervals($service, $start, $end) : collect();
        $cursor = $start->copy()->setTimezone($timezone);
        $cursor = $aggregation === 'daily' ? $cursor->startOfDay() : $cursor->startOfHour();
        $buckets = collect();
        while ($cursor->lt($end)) {
            $next = $aggregation === 'daily' ? $cursor->copy()->addDay() : $cursor->copy()->addHour();
            $a = $cursor->copy()->utc()->max($start);
            $b = $next->copy()->utc()->min($end);
            $group = $grouped->get($cursor->copy()->utc()->toIso8601String(), collect());
            $eligibleStart = $a->copy()->max($service->created_at);
            $segments = $b->gt($eligibleStart) ? $this->intervals->subtract(collect([['start' => $eligibleStart, 'end' => $b]]), $maintenance) : collect();
            $seconds = $this->intervals->seconds($segments);
            $interval = max(1, (int) $service->check_interval_minutes) * 60;
            $expected = (int) ceil($seconds / $interval);
            $slots = [];
            foreach ($group as $check) {
                $offset = 0;
                foreach ($segments as $segment) {
                    if ($check->checked_at->gte($segment['start']) && $check->checked_at->lt($segment['end'])) {
                        $slots[(int) floor(($offset + $segment['start']->diffInSeconds($check->checked_at)) / $interval)] = true;
                        break;
                    }
                    $offset += $segment['start']->diffInSeconds($segment['end']);
                }
            }
            $n = $group->count();
            $d = $group->filter(fn ($c) => $c->is_problematic)->count();
            $buckets->push([
                'bucket_start' => $a->toIso8601String(), 'bucket_end' => $b->toIso8601String(),
                'expected_count' => $expected, 'observed_count' => $n, 'covered_count' => count($slots),
                'missing_count' => max(0, $expected - count($slots)),
                'coverage' => $expected > 0 ? 100 * count($slots) / $expected : null,
                'problematic_count' => $d, 'failed_count' => $group->where('is_success', false)->count(),
                'problematic_proportion' => $n > 0 ? $d / $n : null,
                'status' => $n === 0 ? ($expected > 0 ? 'missing' : 'not_expected') : (count($slots) < $expected ? 'partially_observed' : 'observed'),
            ]);
            $cursor = $next;
        }

        return $buckets;
    }
}
