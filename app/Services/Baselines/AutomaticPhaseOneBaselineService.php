<?php

namespace App\Services\Baselines;

use App\Jobs\PrepareAutomaticPhaseOneBaseline;
use App\Models\BaselinePreparationRun;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\FixedMonitoringCadence;
use App\Services\InstitutionTimezone;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;

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

        return $this->dispatchDue($actor)['dispatched'];
    }

    /**
     * Dispatch incomplete automatic I/MR/P sets fairly. Existing lifecycle
     * decisions are never replaced.
     *
     * @return array{eligible: int, dispatched: int, duplicate: int, skipped: int, deferred: int, failed: int}
     */
    public function dispatchDue(?User $actor = null): array
    {
        if ($actor !== null) {
            $this->authorize($actor);
        }
        $connection = config('queue.connections.'.config('queue.default'));
        // Atomic enqueue is essential: an external broker cannot commit with
        // the ledger. This deployment uses the application database queue.
        if (! Queue::getFacadeRoot() instanceof QueueFake
            && (($connection['driver'] ?? null) !== 'database'
                || ($connection['connection'] ?? config('database.default')) !== config('database.default')
                || ($connection['after_commit'] ?? false))) {
            throw new \LogicException('Baseline dispatch requires the application database queue with after_commit=false.');
        }

        return DB::transaction(function () use ($actor): array {
            $scheduler = DB::table('baseline_preparation_scheduler')->where('id', 1)->lockForUpdate()->first();
            if ($scheduler === null) {
                throw new \LogicException('Baseline scheduler state is missing; apply the migration.');
            }

            return $this->dispatchLocked($actor, (int) $scheduler->last_service_id);
        }, 3);
    }

    private function dispatchLocked(?User $actor, int $cursor): array
    {
        app(SystemHealthService::class)->expectBaselineQueue();
        $policy = $this->policy();
        $timezone = $this->institutionTimezone->name();
        $cutoff = now($timezone)->startOfDay()->utc()->startOfSecond();
        $counts = ['eligible' => 0, 'dispatched' => 0, 'duplicate' => 0, 'skipped' => 0, 'deferred' => 0, 'failed' => 0];
        $maximum = min(5, max(1, (int) ($policy['maximum_jobs_per_run'] ?? 5)));
        $backlogLimit = max(1, (int) ($policy['backlog_limit'] ?? 25));
        $queueState = $this->baselineQueueState();
        $backlog = $queueState['backlog'];

        // Resume after the last successful enqueue, wrapping once. A deleted
        // cursor target is harmless, and new services join the same rotation.
        $services = MonitoredService::query()
            ->where('is_active', true)
            ->whereIn('check_type', ['http', 'api'])
            ->orderByRaw('CASE WHEN id > ? THEN 0 ELSE 1 END', [$cursor])
            ->orderBy('id')
            ->get();

        foreach ($services as $service) {
            $counts['eligible']++;
            $complete = ControlChartBaseline::query()
                ->where('monitored_service_id', $service->id)
                ->where('selection_policy_version', $policy['version'])
                ->whereIn('chart_type', ['i_chart', 'mr_chart', 'p_chart'])
                ->distinct()->count('chart_type') === 3;
            if ($complete) {
                $counts['skipped']++;

                continue;
            }

            $alreadyQueued = BaselinePreparationRun::query()
                ->where('monitored_service_id', $service->id)
                ->where('policy_version', $policy['version'])
                ->where(function ($query) use ($cutoff): void {
                    $query->whereIn('status', ['pending', 'processing'])
                        ->orWhere('cutoff_at', $cutoff);
                })
                ->exists();
            if ($alreadyQueued || isset($queueState['services'][$service->id])) {
                $counts['duplicate']++;

                continue;
            }

            if ($counts['dispatched'] >= $maximum || $backlog >= $backlogLimit) {
                $counts['deferred']++;

                continue;
            }

            try {
                $run = DB::transaction(function () use ($service, $policy, $cutoff, $timezone, $actor): ?BaselinePreparationRun {
                    $run = BaselinePreparationRun::query()->firstOrCreate([
                        'monitored_service_id' => $service->id,
                        'policy_version' => $policy['version'],
                        'cutoff_at' => $cutoff,
                    ], [
                        'analysis_timezone' => $timezone,
                        'status' => 'pending',
                        'dispatched_at' => now(),
                    ]);
                    if (! $run->wasRecentlyCreated) {
                        return null;
                    }

                    $queued = Queue::push(new PrepareAutomaticPhaseOneBaseline(
                        $service->id,
                        $actor?->id,
                        $cutoff->toIso8601String(),
                        $timezone,
                        $policy['version'],
                        $run->id,
                    ), '', 'baseline-preparation');
                    if ($queued === false) {
                        throw new \RuntimeException('Baseline queue rejected the job.');
                    }

                    DB::table('baseline_preparation_scheduler')->where('id', 1)
                        ->update(['last_service_id' => $service->id]);

                    return $run;
                }, 3);

                if ($run === null) {
                    $counts['duplicate']++;
                } else {
                    $counts['dispatched']++;
                    $backlog++;
                }
            } catch (\Throwable $exception) {
                $counts['failed']++;
                Log::error('Automatic Phase I baseline dispatch failed.', [
                    'service_id' => $service->id,
                    'policy_version' => $policy['version'],
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $counts;
    }

    /** @return array{backlog: int, services: array<int, true>} */
    private function baselineQueueState(): array
    {
        $active = BaselinePreparationRun::query()->whereIn('status', ['pending', 'processing'])->get(['id', 'monitored_service_id']);
        $identities = $active->mapWithKeys(fn ($run): array => ['run:'.$run->id => true])->all();
        $services = $active->filter(fn ($run): bool => $run->monitored_service_id !== null)
            ->mapWithKeys(fn ($run): array => [$run->monitored_service_id => true])->all();
        $connection = config('queue.connections.'.config('queue.default'));
        if (($connection['driver'] ?? null) === 'database') {
            // Inspect JSON and scalar serialized fields only. Never unserialize
            // queue payloads in the scheduler. Include legacy and retried jobs,
            // which may have no active ledger row, in duplicate/backlog checks.
            $jobs = DB::connection($connection['connection'] ?? null)
                ->table($connection['table'] ?? 'jobs')->where('queue', 'baseline-preparation')
                ->get(['id', 'payload']);
            foreach ($jobs as $job) {
                $data = json_decode($job->payload, true);
                $command = $data['data']['command'] ?? '';
                $runId = null;
                if (($data['data']['commandName'] ?? null) === PrepareAutomaticPhaseOneBaseline::class) {
                    if (preg_match('/s:9:"serviceId";i:(\d+);/', $command, $match)) {
                        $services[(int) $match[1]] = true;
                    }
                    if (preg_match('/s:16:"preparationRunId";i:(\d+);/', $command, $match)) {
                        $runId = (int) $match[1];
                    }
                }
                $identities[$runId === null ? 'job:'.$job->id : 'run:'.$runId] = true;
            }
        }

        return ['backlog' => count($identities), 'services' => $services];
    }

    /** @return array{created: array, existing: array, unavailable: array, cutoff: string, policy_version: string} */
    public function prepareOne(int $serviceId, ?User $actor, string $cutoff, string $timezone, string $policyVersion): array
    {
        if ($actor) {
            $this->authorize($actor);
        }
        $policy = $this->policy();
        if ($policyVersion !== $policy['version'] || $timezone !== $this->institutionTimezone->name()) {
            throw new \LogicException('The queued automatic Phase I policy context is no longer current.');
        }
        $end = Carbon::parse($cutoff, 'UTC')->utc()->startOfSecond();
        $localEnd = $end->copy()->setTimezone($timezone);
        if (! $localEnd->equalTo($localEnd->copy()->startOfDay()) || $end->gt(now($timezone)->startOfDay()->utc())) {
            throw new \LogicException('The queued automatic Phase I cutoff is not a completed local-day boundary.');
        }
        $service = MonitoredService::query()->where('is_active', true)->whereIn('check_type', ['http', 'api'])->find($serviceId);
        $result = ['created' => [], 'existing' => [], 'unavailable' => [], 'cutoff' => $end->toIso8601String(), 'policy_version' => $policy['version']];
        if ($service) {
            $this->prepareService($service, $end, $timezone, $policy, $actor, $result);
        }

        return $result;
    }

    private function prepareService(MonitoredService $service, Carbon $end, string $timezone, array $policy, ?User $actor, array &$result): void
    {
        $existing = $this->automaticTypes($service->id, $policy['version']);
        if ($existing->count() === 3) {
            array_push($result['existing'], ...$existing->values()->all());

            return;
        }

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
            $existing = $this->automaticTypes($service->id, $policy['version']);
            array_push($result['existing'], ...$existing->values()->all());
            if ($existing->count() === 3) {
                return;
            }
            // A recovered chart remains part of the original automatic set.
            // Lifecycle status is deliberately irrelevant: rejected/retired
            // members are preserved and never replaced or reactivated.
            $draftSetId = $existing->first(fn (ControlChartBaseline $baseline): bool => filled($baseline->draft_set_id))?->draft_set_id
                ?? (string) Str::uuid();
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
                if ($existing->has($type)) {
                    continue;
                }
                $identity = $this->identity($service, $type, $policy['version'], $selection['start'], $end, $timezone);
                $sameWindow = ControlChartBaseline::query()->where('automatic_identity', $identity)->first();
                if ($sameWindow) {
                    $result['existing'][] = $sameWindow;

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
                $baseline = $actor
                    ? $this->engine->create(
                        $service, $type, $selection['start']->toIso8601String(), $end->toIso8601String(), $timezone,
                        $actor, 'hourly', $end->toIso8601String(), $draftSetId, $trace,
                    )
                    : $this->engine->createAutomatically(
                        $service, $type, $selection['start']->toIso8601String(), $end->toIso8601String(), $timezone,
                        'hourly', $end->toIso8601String(), $draftSetId, $trace,
                    );
                $result['created'][] = $baseline;
            }
        }, 3);
    }

    private function automaticTypes(int $serviceId, string $policyVersion)
    {
        return ControlChartBaseline::query()
            ->where('monitored_service_id', $serviceId)
            ->where('selection_policy_version', $policyVersion)
            ->whereIn('chart_type', ['i_chart', 'mr_chart', 'p_chart'])
            ->orderByDesc('id')
            ->get()
            ->unique('chart_type')
            ->keyBy('chart_type');
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
