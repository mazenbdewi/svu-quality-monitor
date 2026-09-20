<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Exports\Reports\Sheets\Concerns\FormatsReportValues;
use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\ResearchInterpretationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class SummarySheet implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;
    use FormatsReportValues;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function collection(): Collection
    {
        $rows = collect([
            [__('monitoring.reports.summary.date_range'), $this->dateRangeLabel()],
            [__('monitoring.reports.summary.total_services'), MonitoredService::query()->count()],
            [__('monitoring.reports.summary.active_services'), MonitoredService::query()->where('is_active', true)->count()],
            [__('monitoring.reports.summary.total_checks'), $this->checksQuery()->count()],
            [__('monitoring.reports.summary.successful_checks'), (clone $this->checksQuery())->where('is_success', true)->count()],
            [__('monitoring.reports.summary.failed_checks'), (clone $this->checksQuery())->where('is_success', false)->count()],
            [__('monitoring.reports.summary.slow_checks'), (clone $this->checksQuery())->where('is_slow', true)->count()],
            [__('monitoring.reports.summary.open_incidents'), (clone $this->incidentsQuery())->where('status', 'open')->count()],
            [__('monitoring.reports.summary.closed_incidents'), (clone $this->incidentsQuery())->where('status', 'closed')->count()],
            [__('monitoring.reports.summary.average_availability'), $this->averageAvailability()],
            [__('monitoring.reports.summary.average_response_time'), $this->averageResponseTime()],
            [__('monitoring.reports.summary.total_control_charts'), $this->controlChartsQuery()->count()],
            [__('monitoring.reports.summary.total_out_of_control_points'), $this->outOfControlPointsQuery()->count()],
        ]);

        $findings = app(ResearchInterpretationService::class)->comprehensiveReportFindings($this->filters);

        if ($findings !== []) {
            $rows->push(['', '']);
            $rows->push([__('monitoring.interpretation.sections.key_findings_and_recommendations'), '']);
            $rows->push([__('monitoring.interpretation.labels.finding'), __('monitoring.interpretation.labels.recommendation')]);

            foreach ($findings as $finding) {
                $rows->push([
                    $finding['title'].' - '.$finding['message'],
                    $finding['recommendation'],
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            __('monitoring.reports.summary.metric'),
            __('monitoring.reports.summary.value'),
        ];
    }

    public function title(): string
    {
        return __('monitoring.reports.sheets.summary');
    }

    protected function dateRangeLabel(): string
    {
        $from = $this->filters['date_from'] ?? null;
        $to = $this->filters['date_to'] ?? null;

        if (! $from && ! $to) {
            return __('monitoring.reports.summary.all_dates');
        }

        return ($from ? $this->date($from) : __('monitoring.reports.summary.open_start'))
            .' - '
            .($to ? $this->date($to) : __('monitoring.reports.summary.open_end'));
    }

    protected function averageAvailability(): string
    {
        $query = ReliabilityMetric::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
        $average = ReliabilityMetric::weightedAvailability($query);

        return $average === null ? '' : number_format((float) $average, 4).'%';
    }

    protected function averageResponseTime(): string
    {
        $average = $this->checksQuery()->avg('response_time_ms');

        return $average === null ? '' : number_format((float) $average, 2);
    }

    protected function checksQuery(): Builder
    {
        return ServiceCheck::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('checked_at', '<=', $date));
    }

    protected function incidentsQuery(): Builder
    {
        return ServiceIncident::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('started_at', '<=', $date));
    }

    protected function outOfControlPointsQuery(): Builder
    {
        return ControlChartPoint::query()
            ->where('is_out_of_control', true)
            ->whereHas('controlChart', function (Builder $query): void {
                $query
                    ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
                    ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
                    ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
                    ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
                    ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
                    ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
            });
    }

    protected function controlChartsQuery(): Builder
    {
        return ControlChart::query()
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['chart_type'] ?? null, fn (Builder $query, $chartType): Builder => $query->where('chart_type', $chartType))
            ->when($this->filters['metric_name'] ?? null, fn (Builder $query, $metricName): Builder => $query->where('metric_name', $metricName))
            ->when($this->filters['period_type'] ?? null, fn (Builder $query, $periodType): Builder => $query->where('period_type', $periodType))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '>=', $date))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('period_start', '<=', $date));
    }
}
