<?php

namespace App\Reports;

use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\ResearchInterpretationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ComprehensivePdfReport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private array $filters = []) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'generatedAt' => now(),
            'dateRange' => $this->dateRangeLabel(),
            'selectedService' => $this->selectedServiceName(),
            'summary' => $this->summary(),
            'keyFindings' => app(ResearchInterpretationService::class)->comprehensiveReportFindings($this->filters),
            'serviceChecksSummary' => $this->serviceChecksSummary(),
            'incidentsSummary' => $this->incidentsSummary(),
            'reliabilityMetricsSummary' => $this->reliabilityMetricsSummary(),
            'controlChartsSummary' => $this->controlChartsSummary(),
            'outOfControlPoints' => $this->outOfControlPoints(),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        return [
            'total_services' => $this->servicesQuery()->count(),
            'active_services' => (clone $this->servicesQuery())->where('is_active', true)->count(),
            'total_checks' => $this->checksQuery()->count(),
            'successful_checks' => (clone $this->checksQuery())->where('is_success', true)->count(),
            'failed_checks' => (clone $this->checksQuery())->where('is_success', false)->count(),
            'slow_checks' => (clone $this->checksQuery())->where('is_slow', true)->count(),
            'open_incidents' => (clone $this->incidentsQuery())->where('status', 'open')->count(),
            'closed_incidents' => (clone $this->incidentsQuery())->where('status', 'closed')->count(),
            'average_availability' => $this->percent($this->reliabilityMetricsQuery()->avg('availability_percent'), 4),
            'average_response_time' => $this->milliseconds($this->checksQuery()->whereNotNull('response_time_ms')->avg('response_time_ms')),
            'total_control_charts' => $this->controlChartsQuery()->count(),
            'out_of_control_points_count' => $this->outOfControlPointsQuery()->count(),
        ];
    }

    public function hasData(): bool
    {
        return $this->checksQuery()->exists()
            || $this->incidentsQuery()->exists()
            || $this->reliabilityMetricsQuery()->exists()
            || $this->controlChartsQuery()->exists()
            || $this->outOfControlPointsQuery()->exists();
    }

    public function dateRangeLabel(): string
    {
        $from = $this->filters['date_from'] ?? null;
        $to = $this->filters['date_to'] ?? null;

        if (! $from && ! $to) {
            return __('monitoring.pdf_reports.all_dates');
        }

        return ($from ? $this->date($from) : __('monitoring.reports.summary.open_start'))
            .' - '
            .($to ? $this->date($to) : __('monitoring.reports.summary.open_end'));
    }

    public function selectedServiceName(): string
    {
        $serviceId = $this->filters['service_id'] ?? null;

        if (! $serviceId) {
            return __('monitoring.pdf_reports.all_services');
        }

        return MonitoredService::query()->whereKey($serviceId)->value('name')
            ?? __('monitoring.pdf_reports.all_services');
    }

    /**
     * @return Collection<int, MonitoredService>
     */
    public function serviceChecksSummary(): Collection
    {
        return MonitoredService::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->whereKey($serviceId))
            ->whereHas('serviceChecks', fn (Builder $query): Builder => $this->applyCheckDateFilters($query))
            ->withCount([
                'serviceChecks as total_checks' => fn (Builder $query): Builder => $this->applyCheckDateFilters($query),
                'serviceChecks as successful_checks' => fn (Builder $query): Builder => $this->applyCheckDateFilters($query)->where('is_success', true),
                'serviceChecks as failed_checks' => fn (Builder $query): Builder => $this->applyCheckDateFilters($query)->where('is_success', false),
                'serviceChecks as slow_checks' => fn (Builder $query): Builder => $this->applyCheckDateFilters($query)->where('is_slow', true),
            ])
            ->withAvg([
                'serviceChecks as average_response_time' => fn (Builder $query): Builder => $this->applyCheckDateFilters($query)->whereNotNull('response_time_ms'),
            ], 'response_time_ms')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, MonitoredService>
     */
    public function incidentsSummary(): Collection
    {
        return MonitoredService::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->whereKey($serviceId))
            ->whereHas('serviceIncidents', fn (Builder $query): Builder => $this->applyIncidentDateFilters($query))
            ->withCount([
                'serviceIncidents as open_incidents' => fn (Builder $query): Builder => $this->applyIncidentDateFilters($query)->where('status', 'open'),
                'serviceIncidents as closed_incidents' => fn (Builder $query): Builder => $this->applyIncidentDateFilters($query)->where('status', 'closed'),
                'serviceIncidents as total_incidents' => fn (Builder $query): Builder => $this->applyIncidentDateFilters($query),
            ])
            ->withAvg([
                'serviceIncidents as average_duration_minutes' => fn (Builder $query): Builder => $this->applyIncidentDateFilters($query)->whereNotNull('duration_minutes'),
            ], 'duration_minutes')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, ReliabilityMetric>
     */
    public function reliabilityMetricsSummary(): Collection
    {
        return ReliabilityMetric::query()
            ->with('monitoredService:id,name')
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')
                    ->from('reliability_metrics')
                    ->when($this->filters['service_id'] ?? null, fn ($query, $serviceId) => $query->where('monitored_service_id', $serviceId))
                    ->when($this->filters['period_type'] ?? null, fn ($query, $periodType) => $query->where('period_type', $periodType))
                    ->when($this->filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('period_start', '>=', $date))
                    ->when($this->filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('period_start', '<=', $date))
                    ->groupBy('monitored_service_id');
            })
            ->orderBy('period_start')
            ->get();
    }

    /**
     * @return Collection<int, ControlChart>
     */
    public function controlChartsSummary(): Collection
    {
        return $this->controlChartsQuery()
            ->with('monitoredService:id,name')
            ->orderBy('period_start')
            ->orderBy('chart_type')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, ControlChartPoint>
     */
    public function outOfControlPoints(): Collection
    {
        return $this->outOfControlPointsQuery()
            ->with('controlChart.monitoredService:id,name')
            ->latest('point_time')
            ->limit(20)
            ->get();
    }

    public function chartTypeLabel(?string $value): string
    {
        return $value ? __("monitoring.chart_types.{$value}") : '';
    }

    public function metricNameLabel(?string $value): string
    {
        return $value ? __("monitoring.metrics.{$value}") : '';
    }

    public function signalTypeLabel(?string $value): string
    {
        return $value ? __("monitoring.control_charts.signal_types.{$value}") : '';
    }

    public function dateTime(mixed $value): string
    {
        if (! $value) {
            return '-';
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('Y-m-d H:i:s');
    }

    public function date(mixed $value): string
    {
        if (! $value) {
            return '-';
        }

        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $date->format('Y-m-d');
    }

    public function number(mixed $value, int $decimals = 2): string
    {
        return $value === null ? '-' : number_format((float) $value, $decimals);
    }

    public function percent(mixed $value, int $decimals = 4): string
    {
        return $value === null ? '-' : number_format((float) $value, $decimals).'%';
    }

    public function milliseconds(mixed $value): string
    {
        return $value === null ? '-' : number_format((float) $value).' ms';
    }

    private function servicesQuery(): Builder
    {
        return MonitoredService::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->whereKey($serviceId));
    }

    private function checksQuery(): Builder
    {
        return $this->applyCheckDateFilters(ServiceCheck::query())
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId));
    }

    private function incidentsQuery(): Builder
    {
        return $this->applyIncidentDateFilters(ServiceIncident::query())
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId));
    }

    private function reliabilityMetricsQuery(): Builder
    {
        return ReliabilityMetric::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
    }

    private function controlChartsQuery(): Builder
    {
        return ControlChart::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
            ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
            ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
    }

    private function outOfControlPointsQuery(): Builder
    {
        return ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('point_time', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('point_time', '<=', $date))
            ->whereHas('controlChart', function (Builder $query): void {
                $query
                    ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
                    ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
                    ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
                    ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType));
            });
    }

    private function applyCheckDateFilters(Builder $query): Builder
    {
        return $query
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '<=', $date));
    }

    private function applyIncidentDateFilters(Builder $query): Builder
    {
        return $query
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date));
    }
}
