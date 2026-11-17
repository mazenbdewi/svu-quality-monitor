<?php

namespace App\Services;

use App\Models\MonitoredService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Research-only proposal; never creates or approves an operational SLO. */
class SloCalibrationAnalysis
{
    public const POLICY_VERSION = 'sre-slo-calibration-v1';

    public const ROUNDING_QUANTUM_MS = 100;

    public const MINIMUM_COVERAGE_PERCENT = 95;

    public const PROVISIONAL_COMBINED_COMPLIANCE_PERCENT = 99;

    public function __construct(
        private LatencyPercentileAnalysis $latency,
        private SliIndicatorAnalysis $indicators,
    ) {}

    /** @return array<string, mixed> */
    public function propose(MonitoredService $service): array
    {
        return DB::transaction(function () use ($service): array {
            $historical = $this->latency->analyze($service);
            $fast = $this->roundUp($historical['p90_ms']);
            $combined = $this->roundUp($historical['p99_ms']);
            $start = Carbon::parse($historical['period_start']);
            $end = Carbon::parse($historical['period_end']);
            $mixedRegime = app(FixedMonitoringCadence::class)->crossesRegime($service, $start, $end);
            $obsoleteRegime = app(FixedMonitoringCadence::class)->referenceIncompatible($service, $start, $end);
            $sli = $this->indicators->describe($service, $start, $end, $combined);
            $warnings = $historical['configuration_warnings'];
            if ($historical['sample_status'] !== 'analyzable') {
                $warnings[] = 'insufficient_latency_observations';
            }
            if ($mixedRegime) {
                $warnings[] = 'monitoring_sampling_regime_crosses_calibration_window';
            } elseif ($obsoleteRegime) {
                $warnings[] = 'monitoring_sampling_regime_obsolete_at_preparation';
            }
            if (($historical['coverage']['coverage_percent'] ?? 0) < self::MINIMUM_COVERAGE_PERCENT) {
                $warnings[] = 'insufficient_coverage';
            }
            if ($sli['executed_checks'] === 0) {
                $warnings[] = 'no_executed_checks';
            }
            $availabilityBelowTarget = $sli['availability_percent'] !== null
                && $sli['availability_percent'] < self::PROVISIONAL_COMBINED_COMPLIANCE_PERCENT;
            $combinedBelowTarget = $sli['combined_latency_success_percent'] !== null
                && $sli['combined_latency_success_percent'] < self::PROVISIONAL_COMBINED_COMPLIANCE_PERCENT;
            if ($availabilityBelowTarget) {
                $warnings[] = 'combined_target_unattainable_given_calibration_availability';
            }
            if ($combinedBelowTarget) {
                $warnings[] = 'in_sample_combined_below_proposed_target';
            }
            $warnings[] = 'p99_tail_uncertainty_not_quantified';
            $warnings[] = 'historical_configuration_and_maintenance_not_versioned';
            $warnings[] = 'user_experience_validation_required';
            $eligible = $historical['sample_status'] === 'analyzable'
                && ($historical['coverage']['coverage_percent'] ?? 0) >= self::MINIMUM_COVERAGE_PERCENT && ! $obsoleteRegime;

            $proposal = [
                'policy_version' => self::POLICY_VERSION,
                'status' => $eligible ? 'provisional_research_only' : 'insufficient_evidence',
                'service_id' => $service->id,
                'calibration_start' => $historical['period_start'],
                'calibration_end' => $historical['period_end'],
                'calibration_cutoff' => $historical['data_cutoff'],
                'analysis_timezone' => $historical['analysis_timezone'],
                'boundary' => '[start,end)',
                'latency_method' => $historical['method'],
                'latency_observations' => $historical['latency_observations'],
                'measured_p90_ms' => $historical['p90_ms'],
                'measured_p99_ms' => $historical['p99_ms'],
                'availability_sli_percent' => $sli['availability_percent'],
                'calibration_conditional_successful_latency_percent' => $sli['conditional_successful_latency_percent'],
                'calibration_combined_sli_percent' => $sli['combined_latency_success_percent'],
                'calibration_combined_sli_is_independent_evidence' => false,
                'calibration_availability_below_proposed_combined_target' => $availabilityBelowTarget,
                'calibration_combined_below_proposed_target' => $combinedBelowTarget,
                'executed_checks' => $sli['executed_checks'],
                'successful_checks' => $sli['successful_checks'],
                'failed_checks' => $sli['failed_checks'],
                'successful_without_valid_latency' => $sli['successful_without_valid_latency'],
                'successful_with_valid_latency' => $sli['successful_with_valid_latency'],
                'successful_within_proposed_objective' => $sli['successful_within_objective'],
                'coverage' => $historical['coverage'],
                'minimum_observations_policy' => $historical['minimum_observations_policy'],
                'minimum_coverage_percent_policy' => self::MINIMUM_COVERAGE_PERCENT,
                'proposed_p90_guardrail_ms' => $fast,
                'proposed_combined_latency_objective_ms' => $combined,
                'proposed_combined_compliance_percent' => self::PROVISIONAL_COMBINED_COMPLIANCE_PERCENT,
                'availability_target_percent' => null,
                'rounding_rule' => 'ceil_to_100ms_with_100ms_floor',
                'configuration_fingerprint' => $this->configurationFingerprint($service, $historical['analysis_timezone']),
                'warnings' => array_values(array_unique($warnings)),
            ];
            $proposal['proposal_fingerprint'] = hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR));

            return $proposal;
        });
    }

    private function roundUp(?float $milliseconds): ?int
    {
        return $milliseconds === null ? null : max(self::ROUNDING_QUANTUM_MS,
            (int) (ceil($milliseconds / self::ROUNDING_QUANTUM_MS) * self::ROUNDING_QUANTUM_MS));
    }

    public function configurationFingerprint(MonitoredService $service, string $timezone): string
    {
        $configuration = [
            'service_id' => $service->id,
            'url' => $service->url,
            'check_type' => $service->check_type->value,
            'check_config' => $service->check_config,
            'expected_status_code' => $service->expected_status_code,
            'expected_keyword' => $service->expected_keyword,
            'check_interval_minutes' => $service->check_interval_minutes,
            'warning_response_ms' => $service->warning_response_ms,
            'critical_response_ms' => $service->critical_response_ms,
            'analysis_timezone' => $timezone,
        ];

        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
    }
}
