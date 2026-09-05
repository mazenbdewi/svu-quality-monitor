<?php

namespace App\Reports;

use App\Models\InstitutionSetting;
use App\Models\SlaMetric;
use App\Services\ExecutiveDashboardService;
use Carbon\Carbon;

class ExecutiveMonthlyPdfReport
{
    private Carbon $start;

    private Carbon $end;

    public function __construct(string $month)
    {
        $this->start = Carbon::parse($month, config('app.timezone'))->startOfMonth();
        $this->end = $this->start->copy()->endOfMonth();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $dashboard = app(ExecutiveDashboardService::class)->snapshot($this->end);
        $previousStart = $this->start->copy()->subMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();
        $previous = app(ExecutiveDashboardService::class)->snapshot($previousEnd);
        $previousHasData = SlaMetric::query()->where('period_start', $previousStart)->where('period_end', $previousEnd)->exists();

        return [
            'institution' => InstitutionSetting::current(), 'periodStart' => $this->start, 'periodEnd' => $this->end,
            'dashboard' => $dashboard, 'previous' => $previousHasData ? $previous : null,
            'summaryText' => $this->summaryText($dashboard), 'recommendations' => $this->recommendations($dashboard),
        ];
    }

    public function hasData(): bool
    {
        return SlaMetric::query()->where('period_start', $this->start)->where('period_end', $this->end)->exists();
    }

    private function summaryText(array $dashboard): string
    {
        $availability = $dashboard['sla']['weighted_availability'];

        return __('monitoring.executive.report.summary_text', [
            'availability' => $availability === null ? '-' : number_format($availability, 2).'%',
            'met' => $dashboard['sla']['met'], 'configured' => $dashboard['sla']['configured'], 'breached' => $dashboard['sla']['breached'],
        ]);
    }

    /** @return array<int, string> */
    private function recommendations(array $dashboard): array
    {
        $recommendations = [];
        foreach ($dashboard['sla']['top_budget_consumers'] as $metric) {
            if ($metric->status === 'breached') {
                $recommendations[] = __('monitoring.executive.report.recommend_breach', ['service' => $metric->monitoredService?->name]);
            } elseif ((float) $metric->error_budget_consumed_percent >= 80) {
                $recommendations[] = __('monitoring.executive.report.recommend_budget', ['service' => $metric->monitoredService?->name]);
            }
        }
        foreach ($dashboard['ssl']['expiring'] as $check) {
            if ((int) data_get($check->metadata, 'days_remaining') <= 14) {
                $recommendations[] = __('monitoring.executive.report.recommend_ssl', ['service' => $check->monitoredService?->name]);
            }
        }
        if ($dashboard['spc']['points'] > 0) {
            $recommendations[] = __('monitoring.executive.report.recommend_spc');
        }

        return array_values(array_unique($recommendations)) ?: [__('monitoring.executive.report.no_recommendations')];
    }
}
