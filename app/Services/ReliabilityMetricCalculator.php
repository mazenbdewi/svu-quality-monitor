<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceIncident;
use Carbon\Carbon;

class ReliabilityMetricCalculator
{
    public function __construct(private MaintenanceWindowService $maintenance) {}

    public function calculateForService(
        MonitoredService $service,
        Carbon $start,
        Carbon $end,
        string $periodType = 'daily',
    ): ReliabilityMetric {
        $periodStart = $start->copy();
        $periodEnd = $end->copy();

        $checksQuery = $service->serviceChecks()
            ->whereBetween('checked_at', [$periodStart, $periodEnd]);

        $totalChecks = (clone $checksQuery)->count();
        $successfulChecks = (clone $checksQuery)
            ->where('is_success', true)
            ->where('is_slow', false)
            ->count();
        $failedChecks = (clone $checksQuery)
            ->where(function ($query): void {
                $query->where('is_success', false)
                    ->orWhere('is_slow', true);
            })
            ->count();

        $incidents = $service->serviceIncidents()
            ->where('started_at', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', $periodStart);
            })
            ->get();

        $incidentsCount = $incidents->count();
        $plannedMaintenanceMinutes = $this->maintenance->overlapMinutes($service, $periodStart, $periodEnd);
        $downtimeMinutes = $incidents->sum(function (ServiceIncident $incident) use ($service, $periodStart, $periodEnd): int {
            [$incidentStart, $incidentEnd] = $this->overlapRange($incident, $periodStart, $periodEnd);

            return max(0, $this->minutesBetween($incidentStart, $incidentEnd) - $this->maintenance->overlapMinutes($service, $incidentStart, $incidentEnd));
        });

        $totalPeriodMinutes = $this->minutesBetween($periodStart, $periodEnd);
        $observationMinutes = max($totalPeriodMinutes - $plannedMaintenanceMinutes, 0);
        $uptimeMinutes = max($observationMinutes - $downtimeMinutes, 0);
        $availabilityPercent = $observationMinutes > 0
            ? ($uptimeMinutes / $observationMinutes) * 100
            : 0;

        return ReliabilityMetric::query()->updateOrCreate(
            [
                'monitored_service_id' => $service->id,
                'period_type' => $periodType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'total_checks' => $totalChecks,
                'successful_checks' => $successfulChecks,
                'failed_checks' => $failedChecks,
                'incidents_count' => $incidentsCount,
                'uptime_minutes' => $uptimeMinutes,
                'downtime_minutes' => $downtimeMinutes,
                'planned_maintenance_minutes' => $plannedMaintenanceMinutes,
                'observation_minutes' => $observationMinutes,
                'availability_percent' => round($availabilityPercent, 4),
                'mtbf_minutes' => $incidentsCount > 0 ? round($uptimeMinutes / $incidentsCount, 2) : null,
                'mttr_minutes' => $incidentsCount > 0 ? round($downtimeMinutes / $incidentsCount, 2) : null,
                'failure_rate' => $uptimeMinutes > 0 && $incidentsCount > 0
                    ? round($incidentsCount / $uptimeMinutes, 8)
                    : null,
                'calculated_at' => now(),
            ],
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function overlapRange(ServiceIncident $incident, Carbon $periodStart, Carbon $periodEnd): array
    {
        $effectiveStart = $incident->started_at->greaterThan($periodStart)
            ? $incident->started_at->copy()
            : $periodStart->copy();

        $incidentEnd = $incident->ended_at?->copy() ?? $periodEnd->copy();
        $effectiveEnd = $incidentEnd->lessThan($periodEnd)
            ? $incidentEnd
            : $periodEnd->copy();

        return [$effectiveStart, $effectiveEnd];
    }

    private function minutesBetween(Carbon $start, Carbon $end): int
    {
        $seconds = $start->diffInSeconds($end, false);

        if ($seconds <= 0) {
            return 0;
        }

        return (int) ceil($seconds / 60);
    }
}
