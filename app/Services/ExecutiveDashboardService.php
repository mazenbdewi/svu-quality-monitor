<?php

namespace App\Services;

use App\Models\ControlChartPoint;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ExecutiveDashboardService
{
    /** @return array<string, mixed> */
    public function snapshot(?Carbon $date = null): array
    {
        $now = ($date ?? now())->copy();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $metrics = $this->periodMetrics($start, $end);
        $services = $this->services($now, $metrics);
        $activeMaintenance = MaintenanceWindow::query()
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->with('monitoredServices:id')->get();
        $maintenanceIds = $activeMaintenance->contains('applies_to_all_services', true)
            ? $services->pluck('id')->all()
            : $activeMaintenance->flatMap(fn (MaintenanceWindow $window) => $window->monitoredServices->pluck('id'))->unique()->all();
        $services = $services->map(function (MonitoredService $service) use ($maintenanceIds): MonitoredService {
            $service->setAttribute('executive_under_maintenance', in_array($service->id, $maintenanceIds, true));
            $service->setAttribute('executive_status', $this->status($service));

            return $service;
        });
        $sla = $this->slaSummary($metrics);
        $incidents = $this->incidentSummary($start, $end);

        return [
            'period_start' => $start,
            'period_end' => $end,
            'services' => $services,
            'attention_services' => $this->attentionServices($services),
            'counts' => [
                'total' => $services->count(),
                'healthy' => $services->filter(fn (MonitoredService $service) => $this->status($service) === 'healthy')->count(),
                'down' => $services->filter(fn (MonitoredService $service) => $this->status($service) === 'down')->count(),
                'maintenance' => count($maintenanceIds),
                'open_incidents' => ServiceIncident::query()->where('status', 'open')->count(),
            ],
            'sla' => $sla,
            'incidents' => $incidents,
            'ssl' => $this->sslSummary($now),
            'spc' => $this->spcSummary($start, $end),
            'maintenance' => [
                'active' => $activeMaintenance,
                'upcoming' => MaintenanceWindow::query()->where('starts_at', '>', $now)->orderBy('starts_at')->limit(5)->get(),
            ],
            'response_time' => $this->responseTimeSummary($now),
            'system_health' => app(SystemHealthService::class)->snapshot(),
        ];
    }

    /** @return Collection<int, SlaMetric> */
    public function periodMetrics(Carbon $start, Carbon $end): Collection
    {
        return SlaMetric::query()->with('monitoredService:id,name')
            ->where('period_start', $start)->where('period_end', $end)->get();
    }

    /** @return Collection<int, MonitoredService> */
    private function services(Carbon $now, Collection $metrics): Collection
    {
        $byService = $metrics->keyBy('monitored_service_id');

        return MonitoredService::query()->where('is_active', true)
            ->with(['latestServiceCheck', 'openIncident'])->orderBy('name')->get()
            ->map(function (MonitoredService $service) use ($byService): MonitoredService {
                $service->setRelation('executiveSlaMetric', $byService->get($service->id));

                return $service;
            });
    }

    /** @return array<string, int|float|null> */
    private function slaSummary(Collection $metrics): array
    {
        $eligible = (int) $metrics->sum('eligible_observation_seconds');
        $downtime = (int) $metrics->sum('unplanned_downtime_seconds');

        return [
            'configured' => $metrics->count(),
            'met' => $metrics->where('status', 'met')->count(),
            'at_risk' => $metrics->where('status', 'at_risk')->count(),
            'breached' => $metrics->where('status', 'breached')->count(),
            'eligible_seconds' => $eligible,
            'downtime_seconds' => $downtime,
            'weighted_availability' => $eligible > 0 ? (($eligible - $downtime) / $eligible) * 100 : null,
            'top_budget_consumers' => $metrics->filter(fn (SlaMetric $metric) => $metric->error_budget_consumed_percent !== null)
                ->sortByDesc('error_budget_consumed_percent')->take(5)->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function incidentSummary(Carbon $start, Carbon $end): array
    {
        $incidents = ServiceIncident::query()->with('monitoredService:id,name')->whereNotNull('confirmed_at')
            ->where('started_at', '<', $end)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))->get();
        $durations = $incidents->map(function (ServiceIncident $incident) use ($start, $end): int {
            $from = $incident->started_at->max($start);
            $to = ($incident->ended_at ?? $end)->min($end);

            return max(0, $from->diffInSeconds($to));
        });
        $mostAffected = $incidents->groupBy('monitored_service_id')->sortByDesc(fn (Collection $group) => $group->count())->first();

        return [
            'count' => $incidents->count(), 'downtime_seconds' => $durations->sum(), 'longest_seconds' => $durations->max() ?: 0,
            'most_affected' => $mostAffected?->first()?->monitoredService?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function sslSummary(Carbon $now): array
    {
        $checks = ServiceCheck::query()->with('monitoredService:id,name')->where('check_type', 'ssl')->whereNotNull('metadata')
            ->latest('checked_at')->limit(500)->get()->unique('monitored_service_id')->filter(fn (ServiceCheck $check) => filled(data_get($check->metadata, 'days_remaining')));

        return [
            'within_30' => $checks->filter(fn (ServiceCheck $check) => (int) data_get($check->metadata, 'days_remaining') <= 30)->count(),
            'within_14' => $checks->filter(fn (ServiceCheck $check) => (int) data_get($check->metadata, 'days_remaining') <= 14)->count(),
            'within_7' => $checks->filter(fn (ServiceCheck $check) => (int) data_get($check->metadata, 'days_remaining') <= 7)->count(),
            'expiring' => $checks->sortBy(fn (ServiceCheck $check) => (int) data_get($check->metadata, 'days_remaining'))->take(5)->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function spcSummary(Carbon $start, Carbon $end): array
    {
        $points = ControlChartPoint::query()->with('controlChart.monitoredService:id,name')->where('is_out_of_control', true)
            ->whereBetween('point_time', [$start, $end])->get();

        return ['points' => $points->count(), 'services' => $points->pluck('controlChart.monitoredService.name')->filter()->unique()->values()];
    }

    /** @return array<string, mixed> */
    private function responseTimeSummary(Carbon $now): array
    {
        $start = $now->copy()->subDays(7);
        $checks = ServiceCheck::query()->whereBetween('checked_at', [$start, $now])->whereIn('check_type', ['http', 'api'])
            ->whereNotNull('response_time_ms')->selectRaw('DATE(checked_at) as day, AVG(response_time_ms) as average_ms')->groupBy('day')->orderBy('day')->get();

        return ['days' => $checks];
    }

    /** @return Collection<int, MonitoredService> */
    private function attentionServices(Collection $services): Collection
    {
        $priority = ['down' => 1, 'pending_failure' => 2, 'recovering' => 3, 'breached' => 4, 'at_risk' => 5, 'ssl' => 6, 'critical' => 7, 'healthy' => 8];

        return $services->sortBy(fn (MonitoredService $service) => $priority[$this->attentionStatus($service)] ?? 99)->take(10)->values();
    }

    private function attentionStatus(MonitoredService $service): string
    {
        $status = (string) $service->getAttribute('executive_status');
        if (in_array($status, ['down', 'pending_failure', 'recovering', 'critical'], true)) {
            return $status;
        }
        $sla = $service->getRelation('executiveSlaMetric');
        if ($sla && in_array($sla->status, ['breached', 'at_risk'], true)) {
            return $sla->status;
        }

        return 'healthy';
    }

    private function status(MonitoredService $service): string
    {
        if ($service->getAttribute('executive_under_maintenance')) {
            return 'maintenance';
        }
        if ($service->openIncident !== null && in_array($service->operational_state, ['pending_failure', 'down', 'recovering'], true)) {
            return $service->operational_state;
        }
        if (in_array($service->operational_state, ['pending_failure', 'down', 'recovering'], true)) {
            return $service->operational_state;
        }
        if ($service->latestServiceCheck?->is_success === false) {
            return 'down';
        }
        if ($service->latestServiceCheck?->performance_status === 'critical') {
            return 'critical';
        }

        return 'healthy';
    }
}
