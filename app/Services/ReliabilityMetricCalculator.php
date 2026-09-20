<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use Carbon\Carbon;
use InvalidArgumentException;

class ReliabilityMetricCalculator
{
    public function __construct(
        private MaintenanceWindowService $maintenance,
        private MonitoringCoverageCalculator $coverage,
        private ReliabilityTimeIntervals $intervals,
    ) {}

    public function calculateForService(
        MonitoredService $service,
        Carbon $start,
        Carbon $end,
        string $periodType = 'daily',
        ?Carbon $dataCutoff = null,
    ): ReliabilityMetric {
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Reliability requires a positive half-open period.');
        }
        $periodStart = $start->copy()->utc();
        $periodEnd = $end->copy()->utc();
        $requestedCutoff = $periodEnd->copy()->min(now()->utc());
        if ($dataCutoff !== null) {
            $requestedCutoff = $requestedCutoff->min($dataCutoff->copy()->utc());
        }
        $observationStart = $periodStart->copy()->max($service->created_at);
        $observations = $service->serviceChecks()->automaticObservations()
            ->where('is_during_maintenance', false)
            ->where('checked_at', '>=', $service->created_at)
            ->where('checked_at', '<=', $requestedCutoff);
        $first = (clone $observations)->min('checked_at');
        $last = (clone $observations)->max('checked_at');
        if ($first !== null) {
            $observationStart = $observationStart->max(Carbon::parse($first, 'UTC'));
        }
        // Never extrapolate service state beyond the last automatic observation.
        $effectiveEnd = $last === null ? $observationStart->copy() : $requestedCutoff->copy()->min(Carbon::parse($last, 'UTC'));
        $coverage = $observationStart->lt($requestedCutoff)
            ? $this->coverage->calculate($service, $observationStart, $requestedCutoff)
            : ['status' => 'no_data', 'coverage_percent' => null, 'expected_automatic_checks' => 0, 'observed_automatic_checks' => 0, 'covered_intervals' => 0, 'missing_checks' => 0];
        $hasExposure = $first !== null && $effectiveEnd->gt($observationStart);
        $maintenance = $hasExposure ? $this->maintenance->mergedOverlapIntervals($service, $observationStart, $effectiveEnd) : collect();
        $exposure = $hasExposure ? collect([['start' => $observationStart, 'end' => $effectiveEnd]]) : collect();
        $eligible = $this->intervals->seconds($this->intervals->subtract($exposure, $maintenance));
        $status = $eligible > 0 ? $coverage['status'] : 'no_data';

        $checks = $hasExposure ? (clone $observations)
            ->where('checked_at', '>=', $observationStart)->where('checked_at', '<', $effectiveEnd)->get()
            ->reject(fn ($check) => $maintenance->contains(fn ($i) => $check->checked_at->gte($i['start']) && $check->checked_at->lt($i['end']))) : collect();
        $total = $checks->count();
        if ($total === 0) {
            $status = 'no_data';
        }
        // Retrospective confirmed event history, not a reconstruction of what operators knew then.
        $incidents = $hasExposure ? $service->serviceIncidents()->whereNotNull('confirmed_at')
            ->where('started_at', '<', $effectiveEnd)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $observationStart))->get() : collect();
        $incidentIntervals = $incidents->map(fn ($i) => [
            'start' => $i->started_at->copy()->max($observationStart),
            'end' => ($i->ended_at ?? $effectiveEnd)->copy()->min($effectiveEnd),
        ]);
        $downtime = $this->intervals->seconds($this->intervals->subtract($incidentIntervals, $maintenance));
        $uptime = max(0, $eligible - $downtime);
        // Count first failures only once; a start during excluded maintenance is not a new unplanned failure.
        $starts = $incidents->filter(fn ($i) => $i->started_at->gte($observationStart)
            && ! $maintenance->contains(fn ($m) => $i->started_at->gte($m['start']) && $i->started_at->lt($m['end'])))->count();
        $open = $incidents->filter(fn ($i) => $i->ended_at === null || $i->ended_at->gte($effectiveEnd))->count();
        // Completion cohort: assign each repair to the half-open period containing its end.
        $repairs = $incidents->filter(fn ($i) => $i->status === 'closed' && $i->ended_at !== null
            && $i->ended_at->gte($observationStart) && $i->ended_at->lt($effectiveEnd)
            && $i->ended_at->gt($i->started_at))
            ->map(function ($i) use ($service) {
                return $this->intervals->seconds($this->intervals->subtract(
                    collect([['start' => $i->started_at, 'end' => $i->ended_at]]),
                    $this->maintenance->mergedOverlapIntervals($service, $i->started_at, $i->ended_at),
                ));
            })->filter(fn ($seconds) => $seconds > 0);
        $successful = $checks->where('is_success', true)->count();
        $acceptable = $checks->filter(fn ($check) => ! $check->is_problematic)->count();
        $valid = $status === 'observed';
        $context = [
            'status' => $status,
            'observation_start' => $hasExposure ? $observationStart->toIso8601String() : null,
            'observation_end' => $hasExposure ? $effectiveEnd->toIso8601String() : null,
            'data_cutoff' => $last === null ? null : $effectiveEnd->toIso8601String(),
            'requested_cutoff' => $requestedCutoff->toIso8601String(),
            'eligible_seconds' => $eligible, 'uptime_seconds' => $uptime, 'downtime_seconds' => $downtime,
            'planned_maintenance_seconds' => $this->intervals->seconds($maintenance),
            'coverage' => $coverage, 'incident_start_count' => $starts,
            'open_incident_count' => $open, 'completed_incident_count' => $repairs->count(),
            'completed_repair_seconds' => $repairs->sum(),
            'functional_success_count' => $successful,
            'successful_check_ratio' => $total > 0 ? $successful / $total : null,
            'acceptable_performance_ratio' => $total > 0 ? $acceptable / $total : null,
        ];

        return ReliabilityMetric::query()->updateOrCreate([
            'monitored_service_id' => $service->id, 'period_type' => $periodType,
            'period_start' => $periodStart, 'period_end' => $periodEnd,
        ], [
            'total_checks' => $total, 'successful_checks' => $acceptable, 'failed_checks' => $total - $acceptable,
            'incidents_count' => $starts,
            // Legacy integer-minute columns are presentation-only; exact seconds live in measurement_context.
            'uptime_minutes' => (int) round($uptime / 60), 'downtime_minutes' => (int) round($downtime / 60),
            'observation_minutes' => (int) round($eligible / 60),
            'planned_maintenance_minutes' => (int) round($context['planned_maintenance_seconds'] / 60),
            'availability_percent' => $valid ? round(100 * $uptime / $eligible, 4) : null,
            'mtbf_minutes' => $valid && $starts > 0 && $uptime > 0 ? round($uptime / 60 / $starts, 2) : null,
            'mttr_minutes' => $valid && $repairs->isNotEmpty() ? round($repairs->avg() / 60, 2) : null,
            'failure_rate' => $valid && $uptime > 0 ? round($starts / ($uptime / 60), 8) : null,
            'measurement_context' => $context, 'calculated_at' => now(),
        ]);
    }
}
