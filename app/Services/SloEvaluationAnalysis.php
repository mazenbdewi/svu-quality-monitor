<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\SloProposal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only, post-calibration evaluation of an approved, frozen research objective. */
class SloEvaluationAnalysis
{
    public function __construct(
        private SliIndicatorAnalysis $indicators,
        private LatencyPercentileAnalysis $latency,
        private SloCalibrationAnalysis $calibration,
        private InstitutionTimezone $institutionTimezone,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(SloProposal $proposal, Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();
        if ($start->gte($end) || $end->gt(now()->utc()) || $start->diffInSeconds($end) > 29 * 86400) {
            throw new InvalidArgumentException('Evaluation must be a completed UTC half-open period no longer than 29 days.');
        }

        return DB::transaction(function () use ($proposal, $start, $end): array {
            $proposal = SloProposal::query()->findOrFail($proposal->id);
            if ($proposal->status !== 'approved' || $proposal->approved_at === null) {
                throw new InvalidArgumentException('Only approved proposals may be evaluated.');
            }
            // [calibration_start, cutoff) and [cutoff, evaluation_end) do not overlap.
            if ($start->lt($proposal->calibration_cutoff)) {
                throw new InvalidArgumentException('Evaluation cannot overlap the calibration interval.');
            }
            $mode = match (true) {
                $end->lte($proposal->approved_at) => 'post_calibration_retrospective',
                $start->gt($proposal->approved_at) => 'prospective_after_approval',
                default => throw new InvalidArgumentException('An evaluation period crossing approval must be split into retrospective and prospective intervals.'),
            };
            SloProposalService::assertIntegrity($proposal);
            $service = MonitoredService::query()->findOrFail($proposal->monitored_service_id);
            $sli = $this->indicators->describe($service, $start, $end, $proposal->proposed_latency_ms);
            $coverage = $this->latency->coverageForPeriod($service, $start, $end);
            $currentFingerprint = $this->calibration->configurationFingerprint($service, $this->institutionTimezone->name());
            $compatible = hash_equals($proposal->configuration_fingerprint, $currentFingerprint);
            $warnings = [];
            if (! $compatible) {
                $warnings[] = 'monitoring_configuration_incompatible_with_calibration';
            }
            if ($sli['executed_checks'] === 0) {
                $warnings[] = 'no_executed_checks';
            }
            if ($sli['successful_without_valid_latency'] > 0) {
                $warnings[] = 'successful_checks_without_valid_latency';
            }
            if ($coverage['missing_opportunities'] > 0) {
                $warnings[] = 'missing_monitoring_opportunities';
            }
            $minimumCoverage = (float) $proposal->calibration_payload['minimum_coverage_percent_policy'];
            $adequateCoverage = $coverage['coverage_percent'] !== null && $coverage['coverage_percent'] >= $minimumCoverage;
            if (! $adequateCoverage) {
                $warnings[] = 'insufficient_coverage';
            }
            if ($mode === 'post_calibration_retrospective') {
                $warnings[] = 'retrospective_observations_precede_objective_approval';
            }
            $target = (float) $proposal->proposed_compliance_percent;
            $actual = $sli['combined_latency_success_percent'];
            $assessable = $compatible && $adequateCoverage && $actual !== null;

            return [
                'proposal_id' => $proposal->id,
                'service_id' => $service->id,
                'policy_version' => $proposal->policy_version,
                'evaluation_start' => $start->toIso8601String(),
                'evaluation_end' => $end->toIso8601String(),
                'boundary' => '[start,end)',
                'analysis_timezone' => $proposal->analysis_timezone,
                'calibration_cutoff' => $proposal->calibration_cutoff->toIso8601String(),
                'approved_at' => $proposal->approved_at->toIso8601String(),
                'evaluation_mode' => $mode,
                'objective_basis' => 'approved_provisional_research_calibration_not_externally_validated',
                'frozen_latency_objective_ms' => $proposal->proposed_latency_ms,
                'combined_target_percent' => $target,
                'sli' => $sli,
                'coverage' => $coverage,
                'configuration_compatible' => $compatible,
                'calibration_warnings' => $proposal->calibration_payload['warnings'],
                'target_status' => ! $assessable ? 'insufficient_evidence'
                    : ($mode === 'post_calibration_retrospective'
                        ? ($actual >= $target ? 'retrospective_met' : 'retrospective_shortfall')
                        : ($actual >= $target ? 'met' : 'shortfall')),
                'target_margin_percentage_points' => $assessable ? $actual - $target : null,
                'warnings' => $warnings,
            ];
        });
    }
}
