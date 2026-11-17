<?php

namespace App\Services\Baselines;

use App\Jobs\PrepareAutomaticPhaseOneBaseline;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\FixedMonitoringCadence;
use App\Services\InstitutionTimezone;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Deterministic orchestration around the validated Phase I population/statistics engine. */
class AutomaticPhaseOneBaselineService
{
    public function __construct(
        private BaselinePopulation $population,
        private BaselineStatistics $statistics,
        private PhaseOneBaselineService $engine,
        private InstitutionTimezone $institutionTimezone,
    ) {}

    /** @return array{created: array, existing: array, unavailable: array, cutoff: string, policy_version: string} */
    public function prepare(User $actor): array
    {
        $this->authorize($actor);
        $policy = $this->policy();
        $timezone = $this->institutionTimezone->name();
        $end = now($timezone)->startOfDay()->utc()->startOfSecond();
        $result = ['created' => [], 'existing' => [], 'unavailable' => [], 'cutoff' => $end->toIso8601String(), 'policy_version' => $policy['version']];

        foreach (MonitoredService::query()->whereIn('check_type', ['http', 'api'])->lazyById() as $service) {
            $this->prepareService($service, $end, $timezone, $policy, $actor, $result);
        }

        return $result;
    }

    public function dispatch(User $actor): int
    {
        $this->authorize($actor);
        $policy = $this->policy();
        $timezone = $this->institutionTimezone->name();
        $cutoff = now($timezone)->startOfDay()->utc()->startOfSecond()->toIso8601String();
        $count = 0;

        foreach (MonitoredService::query()->whereIn('check_type', ['http', 'api'])->lazyById() as $service) {
            PrepareAutomaticPhaseOneBaseline::dispatch($service->id, $actor->id, $cutoff, $timezone, $policy['version']);
            $count++;
        }

        return $count;
    }

    /** @return array{created: array, existing: array, unavailable: array, cutoff: string, policy_version: string} */
    public function prepareOne(int $serviceId, User $actor, string $cutoff, string $timezone, string $policyVersion): array
    {
        $this->authorize($actor);
        $policy = $this->policy();
        if ($policyVersion !== $policy['version'] || $timezone !== $this->institutionTimezone->name()) {
            throw new \LogicException('The queued automatic Phase I policy context is no longer current.');
        }
        $end = Carbon::parse($cutoff, 'UTC')->utc()->startOfSecond();
        $localEnd = $end->copy()->setTimezone($timezone);
        if (! $localEnd->equalTo($localEnd->copy()->startOfDay()) || $end->gt(now($timezone)->startOfDay()->utc())) {
            throw new \LogicException('The queued automatic Phase I cutoff is not a completed local-day boundary.');
        }
        $service = MonitoredService::query()->whereIn('check_type', ['http', 'api'])->find($serviceId);
        $result = ['created' => [], 'existing' => [], 'unavailable' => [], 'cutoff' => $end->toIso8601String(), 'policy_version' => $policy['version']];
        if ($service) {
            $this->prepareService($service, $end, $timezone, $policy, $actor, $result);
        }

        return $result;
    }

    private function prepareService(MonitoredService $service, Carbon $end, string $timezone, array $policy, User $actor, array &$result): void
    {
        $longestStart = $end->copy()->setTimezone($timezone)->subDays(max($policy['window_days']))->startOfDay()->utc();
        if (app(FixedMonitoringCadence::class)->referenceIncompatible($service, $longestStart, $end)) {
            $result['unavailable'][] = ['service_id' => $service->id, 'service_name' => $service->name, 'family' => 'i_mr_p', 'reason' => 'monitoring_sampling_regime_incompatible_with_calibration'];

            return;
        }
        $latency = $this->select($service, 'latency', $end, $timezone, $policy);
        $proportion = $this->select($service, 'p_chart', $end, $timezone, $policy);

        DB::transaction(function () use ($service, $end, $timezone, $policy, $actor, $latency, $proportion, &$result): void {
            // Serialize the duplicate check and version allocation before any
            // candidate in this service set is written.
            $service = MonitoredService::query()->lockForUpdate()->findOrFail($service->id);
            $draftSetId = (string) Str::uuid();
            $plans = [];
            if ($latency['included'] > 0) {
                $plans[] = ['type' => 'i_chart', 'selection' => $latency];
                $plans[] = ['type' => 'mr_chart', 'selection' => $latency];
            } else {
                $result['unavailable'][] = ['service_id' => $service->id, 'service_name' => $service->name, 'family' => 'i_mr', 'reason' => 'no_eligible_latency'];
            }
            if ($proportion['included'] > 0) {
                $plans[] = ['type' => 'p_chart', 'selection' => $proportion];
            } else {
                $result['unavailable'][] = ['service_id' => $service->id, 'service_name' => $service->name, 'family' => 'p', 'reason' => 'no_eligible_binary'];
            }

            foreach ($plans as $plan) {
                $type = $plan['type'];
                $selection = $plan['selection'];
                $identity = $this->identity($service, $type, $policy['version'], $selection['start'], $end, $timezone);
                $existing = ControlChartBaseline::query()->where('automatic_identity', $identity)->first();
                if ($existing) {
                    $result['existing'][] = $existing;

                    continue;
                }

                $assessment = $this->assessment($type, $selection, $policy);
                $trace = [
                    'version' => $policy['version'],
                    'identity' => $identity,
                    'window_selection_reason' => $selection['reason'],
                    'actual_window_days' => $selection['days'],
                    'attempted_windows' => $selection['attempts'],
                    'period' => ['start' => $selection['start']->toIso8601String(), 'end' => $end->toIso8601String(), 'boundary' => '[start,end)'],
                    'cutoff' => $end->toIso8601String(),
                    'timezone' => $timezone,
                    'parameters' => $policy,
                    'assessment' => $assessment,
                    'warnings' => $assessment['warnings'],
                    'selection_guard' => 'quantity_only_no_stability_search',
                ];
                $baseline = $this->engine->create(
                    $service,
                    $type,
                    $selection['start']->toIso8601String(),
                    $end->toIso8601String(),
                    $timezone,
                    $actor,
                    'hourly',
                    $end->toIso8601String(),
                    $draftSetId,
                    $trace,
                );
                $result['created'][] = $baseline;
            }
        }, 3);
    }

    private function select(MonitoredService $service, string $family, Carbon $end, string $timezone, array $policy): array
    {
        $attempts = [];
        $selected = null;
        foreach ($policy['window_days'] as $days) {
            // Calendar subtraction occurs in institution time before conversion to UTC.
            $start = $end->copy()->setTimezone($timezone)->subDays($days)->startOfDay()->utc()->startOfSecond();
            $type = $family === 'p_chart' ? 'p_chart' : 'i_chart';
            $capture = $this->population->capture($service, $type, $start, $end, 'hourly', $timezone);
            $i = $family === 'p_chart' ? null : $this->statistics->calculate('i_chart', $capture['samples'], $capture['coverage']['buckets']);
            $mr = $family === 'p_chart' ? null : $this->statistics->calculate('mr_chart', $capture['samples'], $capture['coverage']['buckets']);
            $p = $family === 'p_chart' ? $this->statistics->calculate('p_chart', $capture['samples'], $capture['coverage']['buckets']) : null;
            $quantity = $family === 'p_chart'
                ? $capture['counts']['included'] >= $policy['minimum_p_observations'] && count($p['points']) >= $policy['minimum_p_subgroups']
                : count($i['points']) >= $policy['minimum_i_observations'] && count($mr['points']) >= $policy['minimum_mr_pairs'];
            $attempts[] = [
                'days' => $days,
                'included' => $capture['counts']['included'],
                'i_observations' => $i === null ? null : count($i['points']),
                'mr_pairs' => $mr === null ? null : count($mr['points']),
                'p_subgroups' => $p === null ? null : count($p['points']),
                'quantity_adequate' => $quantity,
            ];
            $selected = compact('days', 'start', 'capture', 'i', 'mr', 'p', 'quantity');
            if ($quantity) {
                break;
            }
        }

        return [
            ...$selected,
            'included' => $selected['capture']['counts']['included'],
            'attempts' => $attempts,
            'reason' => $selected['quantity']
                ? ($selected['days'] === $policy['window_days'][0] ? 'latest_complete_14_days_adequate' : 'expanded_for_sample_adequacy')
                : 'maximum_28_days_still_sample_inadequate',
        ];
    }

    private function assessment(string $type, array $selection, array $policy): array
    {
        $statistics = $selection[$type === 'p_chart' ? 'p' : ($type === 'mr_chart' ? 'mr' : 'i')];
        $capture = $selection['capture'];
        $signals = count(array_filter($statistics['points'], fn (array $point): bool => $point['signal']));
        $p0 = $selection['p']['parameters']['p0'] ?? null;
        $pValue = $p0 === null ? null : (float) $p0;
        $degenerate = $type === 'p_chart' && ($pValue === 0.0 || $pValue === 1.0);
        $adequate = $type === 'p_chart'
            ? $capture['counts']['included'] >= $policy['minimum_p_observations'] && count($selection['p']['points']) >= $policy['minimum_p_subgroups']
            : ($type === 'i_chart'
                ? count($selection['i']['points']) >= $policy['minimum_i_observations']
                : count($selection['mr']['points']) >= $policy['minimum_mr_pairs']);
        $warnings = [];
        if (! $adequate) {
            $warnings[] = 'sample_adequacy_below_project_policy';
        }
        if ($capture['coverage']['missing_count'] > 0) {
            $warnings[] = 'missing_monitoring_opportunities';
        }
        if ($signals > 0) {
            $warnings[] = 'exploratory_out_of_control_points_require_review';
        }
        if ($degenerate) {
            $warnings[] = $pValue === 0.0 ? 'p_zero_nonconforming_degenerate_limits' : 'p_all_nonconforming_degenerate_limits';
        }

        return [
            'sufficiency' => $degenerate ? 'degenerate' : ($adequate ? ($capture['coverage']['missing_count'] > 0 ? 'preliminary' : 'analyzable') : 'insufficient'),
            'sample_adequacy' => ['status' => $adequate ? 'adequate' : 'insufficient', 'included' => $capture['counts']['included'], 'point_count' => count($statistics['points'])],
            'coverage' => ['status' => $capture['coverage']['status'], 'percent' => $capture['coverage']['coverage_percent']],
            'missing_opportunities' => $capture['coverage']['missing_count'],
            'exclusions' => $capture['counts']['exclusion_counts'],
            'statistical_stability' => ['status' => $degenerate ? 'not_assessable' : ($signals > 0 ? 'signals_present' : 'no_rule1_signals_observed'), 'out_of_control_count' => $signals],
            'p_degeneracy' => $type === 'p_chart' ? ['degenerate' => $degenerate, 'p0' => $pValue, 'zero_nonconforming' => $pValue === 0.0] : null,
            'warnings' => $warnings,
        ];
    }

    private function identity(MonitoredService $service, string $type, string $version, Carbon $start, Carbon $end, string $timezone): string
    {
        return hash('sha256', implode('|', [$service->id, $type, $version, $start->toIso8601String(), $end->toIso8601String(), $end->toIso8601String(), $timezone, $type === 'p_chart' ? 'hourly' : 'raw']));
    }

    private function policy(): array
    {
        $policy = config('monitoring.spc.automatic_baseline');
        if (! is_array($policy) || $policy['window_days'] !== [14, 21, 28]) {
            throw new \LogicException('Invalid automatic Phase I policy configuration.');
        }

        return $policy;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->fresh()?->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor->fresh())->authorize(PhaseOneBaselineService::PERMISSION);
    }
}
