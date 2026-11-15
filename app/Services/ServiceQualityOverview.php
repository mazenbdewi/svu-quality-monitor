<?php

namespace App\Services;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\SloProposal;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcSignal;
use App\Services\Baselines\PhaseOneBaselineService;
use Carbon\Carbon;
use InvalidArgumentException;

/** Explicitly loaded, read-only presentation built from the existing research services. */
class ServiceQualityOverview
{
    public function __construct(
        private LatencyPercentileAnalysis $latency,
        private SliIndicatorAnalysis $indicators,
        private SloEvaluationAnalysis $evaluation,
        private PhaseOneBaselineService $baselines,
    ) {}

    /** @return array<string, mixed> */
    public function forService(MonitoredService $service): array
    {
        if (! in_array($service->check_type->value, ['http', 'api'], true)) {
            throw new InvalidArgumentException('The quality overview supports HTTP/API services only.');
        }

        $calculatedAt = now()->utc();
        $latency = $this->latency->analyze($service);
        $canViewResearchProposals = (bool) auth()->user()?->can(SloProposalService::PERMISSION);
        $approved = SloProposal::query()->where('monitored_service_id', $service->id)
            ->where('status', 'approved')->latest('approved_at')->latest('id')->first();
        $latest = $canViewResearchProposals
            ? SloProposal::query()->where('monitored_service_id', $service->id)->latest('id')->first()
            : $approved;
        $prospective = null;
        $retrospective = null;
        if ($approved) {
            $periods = $this->evaluationPeriods($approved, $calculatedAt);
            if ($periods['prospective']) {
                $prospective = $this->evaluation->evaluate($approved, ...$periods['prospective']);
            }
            if ($periods['retrospective']) {
                $retrospective = $this->evaluation->evaluate($approved, ...$periods['retrospective']);
            }
        }
        // With no independent evaluation, report availability only. A calibration
        // threshold is never silently used to present an in-sample combined SLI.
        $sli = $prospective['sli'] ?? $retrospective['sli'] ?? $this->indicators->describe(
            $service, Carbon::parse($latency['period_start']), Carbon::parse($latency['period_end']), null
        );

        return [
            'service_id' => $service->id,
            'calculated_at' => $calculatedAt->toIso8601String(),
            'latency' => $latency,
            'sli' => $sli,
            'sli_basis' => $prospective ? 'prospective_after_approval' : ($retrospective ? 'post_calibration_retrospective' : 'descriptive_28_complete_days'),
            'latest_proposal' => $latest ? $this->proposalSummary($latest) : null,
            'approved_proposal' => $approved ? $this->proposalSummary($approved) : null,
            'proposal_access_limited' => ! $canViewResearchProposals,
            'prospective' => $prospective,
            'retrospective' => $retrospective,
            'spc' => $this->spcStatus($service),
        ];
    }

    /** @return array{prospective: ?array{Carbon, Carbon}, retrospective: ?array{Carbon, Carbon}} */
    private function evaluationPeriods(SloProposal $proposal, Carbon $calculatedAt): array
    {
        $zone = $proposal->analysis_timezone;
        $localEnd = $calculatedAt->copy()->setTimezone($zone)->startOfDay();
        $firstAfterApproval = $proposal->approved_at->copy()->setTimezone($zone)->startOfDay()->addDay();
        $cutoffLocal = $proposal->calibration_cutoff->copy()->setTimezone($zone);
        while ($firstAfterApproval->lte($cutoffLocal)) {
            $firstAfterApproval->addDay();
        }
        $prospectiveStart = $localEnd->copy()->subDays(28)->max($firstAfterApproval)->utc();
        $prospectiveEnd = $localEnd->copy()->utc();
        $retrospectiveEnd = $proposal->approved_at->copy()->min($calculatedAt);
        $retrospectiveStart = $proposal->calibration_cutoff->copy()->max($retrospectiveEnd->copy()->subDays(28));

        return [
            'prospective' => $prospectiveStart->lt($prospectiveEnd) ? [$prospectiveStart, $prospectiveEnd] : null,
            'retrospective' => $retrospectiveStart->lt($retrospectiveEnd) ? [$retrospectiveStart, $retrospectiveEnd] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function proposalSummary(SloProposal $proposal): array
    {
        SloProposalService::assertIntegrity($proposal);
        $payload = $proposal->calibration_payload;

        return [
            'id' => $proposal->id,
            'status' => $proposal->status,
            'scientific_status' => $payload['status'],
            'policy_version' => $proposal->policy_version,
            'calibration_start' => $proposal->calibration_start->toIso8601String(),
            'calibration_end' => $proposal->calibration_end->toIso8601String(),
            'calibration_cutoff' => $proposal->calibration_cutoff->toIso8601String(),
            'analysis_timezone' => $proposal->analysis_timezone,
            'measured_p90_ms' => $payload['measured_p90_ms'],
            'measured_p99_ms' => $payload['measured_p99_ms'],
            'proposed_p90_guardrail_ms' => $payload['proposed_p90_guardrail_ms'],
            'proposed_latency_ms' => $proposal->proposed_latency_ms,
            'proposed_compliance_percent' => (float) $proposal->proposed_compliance_percent,
            'rounding_rule' => $payload['rounding_rule'],
            'warnings' => $payload['warnings'],
        ];
    }

    /** Current Phase II status; historical signals alone are never called healthy. */
    private function spcStatus(MonitoredService $service): array
    {
        $rows = [];
        foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
            $baseline = ControlChartBaseline::query()->where('monitored_service_id', $service->id)
                ->where('chart_type', $type)->where('status', 'approved')->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->latest('version')->first();
            $evaluation = $baseline ? SpcMonitoringEvaluation::query()->where('baseline_id', $baseline->id)
                ->where('mode', 'live')->latest('detected_at')->latest('id')->first() : null;
            $point = $evaluation?->status === 'active' ? SpcMonitoringPoint::query()->where('evaluation_id', $evaluation->id)
                ->where('mode', 'live')->latest('observed_at')->latest('id')->first() : null;
            $signal = $point?->status === 'eligible' ? SpcSignal::query()->where('point_id', $point->id)
                ->where('mode', 'live')->first() : null;
            $compatible = $baseline ? $this->baselines->compatible($baseline) : false;
            $rows[$type] = match (true) {
                ! $service->is_active => 'inactive',
                $baseline === null => 'not_configured',
                ! $compatible, $evaluation?->status === 'baseline_incompatible' => 'incompatible',
                $evaluation === null => 'awaiting_evaluation',
                $evaluation->status !== 'active' => $evaluation->status,
                $evaluation->exposure_until === null || $evaluation->exposure_until->lte(now()) => 'stale',
                $point?->status !== 'eligible' => 'no_current_point',
                $signal?->direction === 'lower' => 'unusual_change',
                $signal !== null => 'signal',
                default => 'normal',
            };
        }

        return $rows;
    }
}
