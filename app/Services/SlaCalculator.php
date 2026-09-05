<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SlaCalculator
{
    public function __construct(private MaintenanceWindowService $maintenance) {}

    public function calculate(MonitoredService $service, Carbon $from, Carbon $to): SlaMetric
    {
        $periodStart = $from->copy()->timezone(config('app.timezone'));
        $periodEnd = $to->copy()->timezone(config('app.timezone'));
        $effectiveStart = $service->created_at->greaterThan($periodStart) ? $service->created_at->copy() : $periodStart->copy();
        $effectiveEnd = now()->lessThan($periodEnd) ? now()->copy() : $periodEnd->copy();

        $target = $service->sla_enabled ? (string) $service->sla_target_percent : null;
        if (! $target || $effectiveEnd->lte($effectiveStart)) {
            return $this->store($service, $periodStart, $periodEnd, $target, 0, 0, 0, null, null, null, null, $target ? 'no_data' : 'not_configured');
        }

        $observationSeconds = $effectiveStart->diffInSeconds($effectiveEnd);
        $maintenanceIntervals = $this->maintenance->mergedOverlapIntervals($service, $effectiveStart, $effectiveEnd);
        $maintenanceSeconds = $this->intervalSeconds($maintenanceIntervals);
        $eligibleSeconds = max(0, $observationSeconds - $maintenanceSeconds);
        if ($eligibleSeconds === 0) {
            return $this->store($service, $periodStart, $periodEnd, $target, 0, $maintenanceSeconds, 0, null, null, null, null, 'no_data');
        }

        $incidentIntervals = $this->confirmedIncidentIntervals($service, $effectiveStart, $effectiveEnd);
        $downtimeSeconds = $this->intervalSeconds($this->subtractIntervals($incidentIntervals, $maintenanceIntervals));
        $availability = (($eligibleSeconds - $downtimeSeconds) / $eligibleSeconds) * 100;
        $allowed = $eligibleSeconds * (1 - ((float) $target / 100));
        $remaining = $allowed - $downtimeSeconds;
        $consumedPercent = $allowed > 0 ? ($downtimeSeconds / $allowed) * 100 : null;
        $atRisk = (float) config('monitoring.sla.at_risk_error_budget_percent', 80);
        $status = $availability < (float) $target ? 'breached' : (($consumedPercent !== null && $consumedPercent >= $atRisk) ? 'at_risk' : 'met');

        return $this->store($service, $periodStart, $periodEnd, $target, $eligibleSeconds, $maintenanceSeconds, $downtimeSeconds, $availability, $allowed, $remaining, $consumedPercent, $status);
    }

    /** @return Collection<int, array{start: Carbon, end: Carbon}> */
    private function confirmedIncidentIntervals(MonitoredService $service, Carbon $from, Carbon $to): Collection
    {
        return $this->mergeIntervals($service->serviceIncidents()
            ->whereNotNull('confirmed_at')->where('started_at', '<', $to)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get()->map(function (ServiceIncident $incident) use ($from, $to): array {
                $start = $incident->started_at->greaterThan($from) ? $incident->started_at->copy() : $from->copy();
                $end = ($incident->ended_at ?? now())->lessThan($to) ? ($incident->ended_at ?? now())->copy() : $to->copy();

                return ['start' => $start, 'end' => $end];
            }));
    }

    /** @param Collection<int, array{start: Carbon, end: Carbon}> $intervals */
    private function mergeIntervals(Collection $intervals): Collection
    {
        $merged = collect();
        foreach ($intervals->sortBy('start') as $interval) {
            $last = $merged->last();
            if ($last && $interval['start']->lte($last['end'])) {
                $last['end'] = $interval['end']->greaterThan($last['end']) ? $interval['end'] : $last['end'];
                $merged->put($merged->count() - 1, $last);
            } else {
                $merged->push($interval);
            }
        }

        return $merged;
    }

    /** @param Collection<int, array{start: Carbon, end: Carbon}> $source @param Collection<int, array{start: Carbon, end: Carbon}> $exclusions */
    private function subtractIntervals(Collection $source, Collection $exclusions): Collection
    {
        $result = collect();
        foreach ($source as $interval) {
            $segments = collect([$interval]);
            foreach ($exclusions as $exclude) {
                $segments = $segments->flatMap(function (array $segment) use ($exclude): array {
                    if ($exclude['end']->lte($segment['start']) || $exclude['start']->gte($segment['end'])) {
                        return [$segment];
                    }

                    return array_values(array_filter([
                        $exclude['start']->greaterThan($segment['start']) ? ['start' => $segment['start'], 'end' => $exclude['start']] : null,
                        $exclude['end']->lessThan($segment['end']) ? ['start' => $exclude['end'], 'end' => $segment['end']] : null,
                    ]));
                });
            }
            $result = $result->concat($segments);
        }

        return $result;
    }

    /** @param Collection<int, array{start: Carbon, end: Carbon}> $intervals */
    private function intervalSeconds(Collection $intervals): int
    {
        return $intervals->sum(fn (array $i): int => max(0, $i['start']->diffInSeconds($i['end'], false)));
    }

    private function store(MonitoredService $service, Carbon $start, Carbon $end, ?string $target, int $eligible, int $maintenance, int $downtime, ?float $availability, ?float $allowed, ?float $remaining, ?float $consumedPercent, string $status): SlaMetric
    {
        return SlaMetric::query()->updateOrCreate(['monitored_service_id' => $service->id, 'period_start' => $start, 'period_end' => $end], [
            'target_percent' => $target, 'eligible_observation_seconds' => $eligible, 'planned_maintenance_seconds' => $maintenance,
            'unplanned_downtime_seconds' => $downtime, 'availability_percent' => $availability, 'allowed_downtime_seconds' => $allowed,
            'error_budget_consumed_seconds' => $downtime, 'error_budget_remaining_seconds' => $remaining,
            'error_budget_consumed_percent' => $consumedPercent, 'status' => $status, 'calculated_at' => now(),
        ]);
    }
}
