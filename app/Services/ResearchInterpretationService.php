<?php

namespace App\Services;

use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResearchInterpretationService
{
    public function availabilityLevel(?float $availability): string
    {
        return match (true) {
            $availability === null => 'no_data',
            $availability >= 99 => 'excellent',
            $availability >= 95 => 'acceptable',
            $availability >= 90 => 'needs_attention',
            default => 'critical',
        };
    }

    public function availabilityColor(?float $availability): string
    {
        return $this->levelColor($this->availabilityLevel($availability));
    }

    /**
     * @return array{level: string, color: string, title: string, message: string, recommendation: string}
     */
    public function reliabilityFinding(ReliabilityMetric $metric): array
    {
        $availability = $metric->availability_percent === null ? null : (float) $metric->availability_percent;
        $level = $this->availabilityLevel($availability);
        $incidentsCount = (int) $metric->incidents_count;
        $downtimeMinutes = (int) $metric->downtime_minutes;
        $mttrMinutes = $metric->mttr_minutes === null ? null : (float) $metric->mttr_minutes;

        if ($level === 'no_data') {
            return $this->finding(
                'no_data',
                __('monitoring.interpretation.reliability.titles.no_data'),
                __('monitoring.interpretation.reliability.messages.no_data'),
                __('monitoring.interpretation.recommendations.continue_monitoring'),
            );
        }

        $titleKey = match (true) {
            $availability >= 99 && $incidentsCount === 0 => 'excellent_stable',
            $availability >= 95 => 'acceptable',
            $availability >= 90 => 'needs_attention',
            default => 'critical',
        };

        $recommendation = match (true) {
            $mttrMinutes !== null && $mttrMinutes > 60 => __('monitoring.interpretation.recommendations.review_recovery_time'),
            $downtimeMinutes > 0 || $incidentsCount > 0 => __('monitoring.interpretation.recommendations.investigate_incidents'),
            $availability < 95 => __('monitoring.interpretation.recommendations.improve_availability'),
            default => __('monitoring.interpretation.recommendations.continue_monitoring'),
        };

        return $this->finding(
            $level,
            __("monitoring.interpretation.reliability.titles.{$titleKey}"),
            __("monitoring.interpretation.reliability.messages.{$titleKey}", [
                'availability' => number_format($availability, 4),
                'incidents' => number_format($incidentsCount),
                'downtime' => number_format($downtimeMinutes),
                'mttr' => $mttrMinutes === null ? __('monitoring.dashboard.empty.value') : number_format($mttrMinutes, 2),
                'failure_rate' => $metric->failure_rate === null ? __('monitoring.dashboard.empty.value') : number_format((float) $metric->failure_rate, 8),
            ]),
            $recommendation,
        );
    }

    /**
     * @return array{level: string, color: string, title: string, message: string, recommendation: string}
     */
    public function controlChartFinding(ControlChart $chart): array
    {
        $state = $chart->analysis_mode === 'exploratory' ? data_get($chart->research_context, 'sufficiency', 'insufficient') : 'legacy';

        return [
            'level' => $state, 'color' => $chart->out_of_control_count > 0 ? 'warning' : 'gray',
            'title' => __('monitoring.spc_research.'.$state),
            'message' => __('monitoring.spc_research.'.$state).' — '.__('monitoring.spc_research.limits'),
            'recommendation' => __('monitoring.spc_research.missing'),
        ];
    }

    /**
     * @return array{level: string, color: string, title: string, message: string, recommendation: string}
     */
    public function serviceStatusFinding(MonitoredService $service): array
    {
        $status = $service->current_status;
        $hasOpenIncident = $service->has_open_incident;
        $level = match ($status) {
            'healthy' => $hasOpenIncident ? 'warning' : 'stable',
            'slow' => 'warning',
            'down' => 'critical',
            default => 'no_data',
        };

        if ($hasOpenIncident && $status !== 'down') {
            $level = 'warning';
        }

        $key = $hasOpenIncident ? 'open_incident' : $status;

        return $this->finding(
            $level,
            __("monitoring.interpretation.service_status.titles.{$key}"),
            __("monitoring.interpretation.service_status.messages.{$key}", [
                'status' => __("monitoring.service_status.statuses.{$status}"),
                'problem' => $service->last_problem_type_label,
            ]),
            match ($key) {
                'healthy' => __('monitoring.interpretation.recommendations.continue_monitoring'),
                'unknown' => __('monitoring.interpretation.recommendations.continue_monitoring'),
                'slow' => __('monitoring.interpretation.recommendations.review_service_performance'),
                'open_incident' => __('monitoring.interpretation.recommendations.investigate_incidents'),
                default => __('monitoring.interpretation.recommendations.investigate_incidents'),
            },
        );
    }

    /**
     * @return array<int, array{type: string, title: string, message: string}>
     */
    public function dashboardFindings(): array
    {
        [$start, $end] = $this->todayRange();

        $openIncidents = ServiceIncident::query()->where('status', 'open')->count();
        $services = MonitoredService::query()
            ->where('is_active', true)
            ->with(['latestServiceCheck', 'openIncident'])
            ->get();
        $downServices = $services->filter(fn (MonitoredService $service): bool => $service->current_status === 'down')->count();
        $slowServices = $services->filter(fn (MonitoredService $service): bool => $service->current_status === 'slow')->count();
        $outOfControlPoints = ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->whereBetween('point_time', [$start, $end])
            ->count();
        $failedChecksToday = ServiceCheck::query()
            ->whereBetween('checked_at', [$start, $end])
            ->where('is_success', false)
            ->count();
        $slowChecksToday = ServiceCheck::query()
            ->whereBetween('checked_at', [$start, $end])
            ->where('is_slow', true)
            ->count();
        $averageAvailability = $this->averageLatestDailyAvailability();

        $findings = [];

        if ($openIncidents > 0) {
            $findings[] = $this->dashboardFinding('danger', 'open_incidents', ['count' => number_format($openIncidents)]);
        }

        if ($downServices > 0) {
            $findings[] = $this->dashboardFinding('danger', 'down_services', ['count' => number_format($downServices)]);
        }

        if ($slowServices > 0) {
            $findings[] = $this->dashboardFinding('warning', 'slow_services', ['count' => number_format($slowServices)]);
        }

        if ($outOfControlPoints > 0) {
            $findings[] = $this->dashboardFinding('warning', 'out_of_control_points', ['count' => number_format($outOfControlPoints)]);
        }

        if ($failedChecksToday > 0) {
            $findings[] = $this->dashboardFinding('warning', 'failed_checks_today', ['count' => number_format($failedChecksToday)]);
        }

        if ($slowChecksToday > 0) {
            $findings[] = $this->dashboardFinding('info', 'slow_checks_today', ['count' => number_format($slowChecksToday)]);
        }

        if ($averageAvailability !== null) {
            $availabilityLevel = $this->availabilityLevel($averageAvailability);
            $findings[] = $this->dashboardFinding(
                $this->levelColor($availabilityLevel),
                "average_availability_{$availabilityLevel}",
                ['availability' => number_format($averageAvailability, 4)],
            );
        }

        if ($findings === []) {
            $findings[] = $this->dashboardFinding('success', 'no_critical_signals');
        }

        return array_slice($findings, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{type: string, title: string, message: string, recommendation: string}>
     */
    public function comprehensiveReportFindings(array $filters = []): array
    {
        $totalChecks = $this->checksQuery($filters)->count();
        $failedChecks = (clone $this->checksQuery($filters))->where('is_success', false)->count();
        $slowChecks = (clone $this->checksQuery($filters))->where('is_slow', true)->count();
        $openIncidents = (clone $this->incidentsQuery($filters))->where('status', 'open')->count();
        $averageAvailability = ReliabilityMetric::weightedAvailability($this->metricsQuery($filters));
        $outOfControlPoints = $this->outOfControlPointsQuery($filters)->count();

        $findings = [];

        if ($totalChecks === 0) {
            $findings[] = $this->reportFinding('info', 'checks_no_data', __('monitoring.interpretation.recommendations.continue_monitoring'));
        } else {
            $findings[] = $this->reportFinding('info', 'total_checks', __('monitoring.interpretation.recommendations.continue_monitoring'), [
                'count' => number_format($totalChecks),
            ]);
        }

        if ($failedChecks > 0) {
            $findings[] = $this->reportFinding('warning', 'failed_checks', __('monitoring.interpretation.recommendations.investigate_incidents'), [
                'count' => number_format($failedChecks),
            ]);
        }

        if ($slowChecks > 0) {
            $findings[] = $this->reportFinding('warning', 'slow_checks', __('monitoring.interpretation.recommendations.review_service_performance'), [
                'count' => number_format($slowChecks),
            ]);
        }

        if ($openIncidents > 0) {
            $findings[] = $this->reportFinding('danger', 'open_incidents', __('monitoring.interpretation.recommendations.investigate_incidents'), [
                'count' => number_format($openIncidents),
            ]);
        }

        if ($averageAvailability !== null) {
            $availability = (float) $averageAvailability;
            $level = $this->availabilityLevel($availability);
            $findings[] = $this->reportFinding($this->levelColor($level), "average_availability_{$level}", match ($level) {
                'excellent', 'acceptable' => __('monitoring.interpretation.recommendations.continue_monitoring'),
                default => __('monitoring.interpretation.recommendations.improve_availability'),
            }, [
                'availability' => number_format($availability, 4),
            ]);
        }

        if ($outOfControlPoints > 0) {
            $findings[] = $this->reportFinding(
                $outOfControlPoints > 2 ? 'danger' : 'warning',
                'out_of_control_points',
                __('monitoring.interpretation.recommendations.investigate_out_of_control_points'),
                ['count' => number_format($outOfControlPoints)],
            );
        }

        if ($failedChecks === 0 && $slowChecks === 0 && $openIncidents === 0 && $outOfControlPoints === 0 && $totalChecks > 0) {
            $findings[] = $this->reportFinding('success', 'no_critical_indicators', __('monitoring.interpretation.recommendations.no_action_required'));
        }

        return array_slice($findings, 0, 7);
    }

    private function levelColor(string $level): string
    {
        return match ($level) {
            'excellent', 'stable' => 'success',
            'acceptable' => 'info',
            'needs_attention', 'warning' => 'warning',
            'critical', 'needs_investigation' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array{level: string, color: string, title: string, message: string, recommendation: string}
     */
    private function finding(string $level, string $title, string $message, string $recommendation): array
    {
        return [
            'level' => $level,
            'color' => $this->levelColor($level),
            'title' => $title,
            'message' => $message,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{type: string, title: string, message: string}
     */
    private function dashboardFinding(string $type, string $key, array $replace = []): array
    {
        return [
            'type' => $type,
            'title' => __("monitoring.interpretation.dashboard.titles.{$key}", $replace),
            'message' => __("monitoring.interpretation.dashboard.messages.{$key}", $replace),
        ];
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{type: string, title: string, message: string, recommendation: string}
     */
    private function reportFinding(string $type, string $key, string $recommendation, array $replace = []): array
    {
        return [
            'type' => $type,
            'title' => __("monitoring.interpretation.reports.titles.{$key}", $replace),
            'message' => __("monitoring.interpretation.reports.messages.{$key}", $replace),
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function todayRange(): array
    {
        return [
            now()->startOfDay(),
            now()->endOfDay(),
        ];
    }

    private function averageLatestDailyAvailability(): ?float
    {
        $todayQuery = ReliabilityMetric::query()
            ->where('period_type', 'daily')
            ->whereDate('period_start', today());
        $todayAverage = ReliabilityMetric::weightedAvailability($todayQuery);

        if ($todayQuery->exists()) {
            return $todayAverage;
        }

        $activeServiceIds = MonitoredService::query()
            ->where('is_active', true)
            ->pluck('id');

        if ($activeServiceIds->isEmpty()) {
            return null;
        }

        /** @var Collection<int, mixed> $metrics */
        $metrics = ReliabilityMetric::query()
            ->whereIn('id', function ($query) use ($activeServiceIds): void {
                $query->selectRaw('MAX(id)')
                    ->from('reliability_metrics')
                    ->where('period_type', 'daily')
                    ->whereIn('monitored_service_id', $activeServiceIds)
                    ->groupBy('monitored_service_id');
            });

        return ReliabilityMetric::weightedAvailability($metrics);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function checksQuery(array $filters): Builder
    {
        return ServiceCheck::query()
            ->when($filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function incidentsQuery(array $filters): Builder
    {
        return ServiceIncident::query()
            ->when($filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function metricsQuery(array $filters): Builder
    {
        return ReliabilityMetric::query()
            ->when($filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function outOfControlPointsQuery(array $filters): Builder
    {
        return ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('point_time', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('point_time', '<=', $date))
            ->whereHas('controlChart', function (Builder $query) use ($filters): void {
                $query
                    ->when($filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
                    ->when($filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
                    ->when($filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
                    ->when($filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType));
            });
    }
}
