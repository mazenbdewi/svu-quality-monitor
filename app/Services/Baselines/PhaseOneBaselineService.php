<?php

namespace App\Services\Baselines;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

class PhaseOneBaselineService
{
    public const PERMISSION = 'control_charts.baselines.manage';

    public function __construct(private BaselinePopulation $population, private BaselineStatistics $statistics, private BaselineConfiguration $configuration, private AuditLogger $audit) {}

    public function create(MonitoredService $service, string $type, string $from, string $to, string $timezone, User $actor, string $aggregation = 'hourly', ?string $cutoff = null): ControlChartBaseline
    {
        $this->authorize($actor);
        if (! in_array($type, ['i_chart', 'mr_chart', 'p_chart'], true) || ! in_array($aggregation, ['hourly', 'daily'], true)) {
            $this->invalid('Only I/MR/P with hourly/daily subgroup policy are supported.');
        }
        new DateTimeZone($timezone);
        $start = Carbon::parse($from, $timezone)->utc()->startOfSecond();
        $end = Carbon::parse($to, $timezone)->utc()->startOfSecond();
        $created = now()->utc()->startOfSecond();
        $dataCutoff = $cutoff ? Carbon::parse($cutoff, $timezone)->utc()->startOfSecond() : $end->copy();
        if ($start->gte($end) || $end->gt($created) || $dataCutoff->gt($end) || $dataCutoff->lte($start)) {
            $this->invalid('Require start < cutoff <= end <= creation time; future baseline periods are not permitted.');
        }
        $aggregation = $type === 'p_chart' ? $aggregation : 'raw';
        $method = $type === 'p_chart' ? 'pooled-binomial-proportion-v1' : 'individuals-moving-range-2-v1';

        return DB::transaction(function () use ($service, $type, $timezone, $actor, $aggregation, $method, $start, $end, $dataCutoff, $created) {
            // Serialize version allocation and configuration capture for this service.
            $service = MonitoredService::query()->lockForUpdate()->findOrFail($service->id);
            $captured = $this->population->capture($service, $type, $start, $dataCutoff, $aggregation === 'raw' ? 'hourly' : $aggregation, $timezone);
            $result = $this->statistics->calculate($type, $captured['samples'], $captured['coverage']['buckets']);
            $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
            $points = count($result['points']);
            $sufficiency = $captured['counts']['included'] === 0 ? 'no_data' : ($points < 2 ? 'insufficient' : ($points < $minimum || $captured['coverage']['missing_count'] > 0 ? 'preliminary' : 'analyzable'));
            $snapshot = $this->configuration->snapshot($service, $timezone, $aggregation, $method);
            $warnings = $this->configuration->changes($service, $start, $end);
            $version = 1 + (int) ControlChartBaseline::query()->where('monitored_service_id', $service->id)->where('chart_type', $type)->max('version');
            $baseline = ControlChartBaseline::create([
                'monitored_service_id' => $service->id, 'chart_type' => $type, 'metric_name' => $type === 'p_chart' ? 'problematic_proportion' : 'response_time_ms',
                'version' => $version, 'status' => 'draft', 'aggregation_interval' => $aggregation, 'analysis_timezone' => $timezone,
                'baseline_start' => $start, 'baseline_end' => $end, 'data_cutoff' => $dataCutoff,
                'calculation_version' => $snapshot['calculation_version'], 'method_identifier' => $method,
                'eligibility_policy_version' => $snapshot['eligibility_policy_version'],
                'sample_counts' => $captured['counts'], 'coverage_context' => $captured['coverage'], 'parameters' => $result['parameters'],
                'configuration_snapshot' => $snapshot, 'compatibility_fingerprint' => $this->configuration->fingerprint($snapshot),
                'membership_digest' => $this->digest($captured['samples']),
                'review_context' => [
                    'service_name' => $service->name, 'sufficiency' => $sufficiency, 'exploratory_min_points_policy' => $minimum,
                    'points_count' => $points, 'exploratory_out_of_control_count' => count(array_filter($result['points'], fn ($p) => $p['signal'])),
                    'distribution' => $result['distribution'], 'configuration_changes' => $warnings,
                    'configuration_history_completeness' => 'not_guaranteed', 'partial' => $dataCutoff->lt($end),
                    'first_included_at' => collect($captured['samples'])->where('included', true)->first()['measurement']['checked_at'] ?? null,
                    'last_included_at' => collect($captured['samples'])->where('included', true)->last()['measurement']['checked_at'] ?? null,
                    'outlier_policy' => 'no_discretionary_exclusions',
                ],
                'created_by' => $actor->id, 'created_at' => $created,
            ]);
            foreach ($captured['samples'] as $sample) {
                $baseline->samples()->create($sample);
            }
            // Only this service writes lifecycle columns; model/bulk application edits are blocked.
            DB::table('control_chart_baselines')->where('id', $baseline->id)->update(['sealed_at' => $created]);
            $baseline->refresh();
            $this->record('created', $baseline, $actor);

            return $baseline;
        }, 3);
    }

    public function review(ControlChartBaseline $baseline, User $actor, string $notes, bool $acknowledgeLimitations): ControlChartBaseline
    {
        return $this->transition($baseline, $actor, function ($row) use ($actor, $notes, $acknowledgeLimitations) {
            if ($row->status !== 'draft' || trim($notes) === '' || ! $acknowledgeLimitations) {
                $this->invalid('Review requires a draft, stability review notes and explicit acknowledgement of coverage, configuration and statistical limitations.');
            }
            $this->reproduce($row);

            return ['status' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_notes' => $notes, 'limitations_acknowledged' => true];
        }, 'reviewed');
    }

    public function approve(ControlChartBaseline $baseline, User $actor, string $reason): ControlChartBaseline
    {
        return $this->transition($baseline, $actor, function ($row) use ($actor, $reason) {
            if ($row->status !== 'reviewed' || ! $row->limitations_acknowledged || trim($reason) === '') {
                $this->invalid('Approval requires explicit completed review and a decision rationale.');
            }
            if (in_array($row->review_context['sufficiency'], ['no_data', 'insufficient'], true) || $row->coverage_context['coverage_percent'] === null) {
                $this->invalid('No-data or insufficient baselines cannot be approved.');
            }
            if (ControlChartBaseline::query()->where('monitored_service_id', $row->monitored_service_id)->where('chart_type', $row->chart_type)->where('status', 'approved')->exists()) {
                $this->invalid('Retire the active version explicitly before approving another version in this family.');
            }
            $this->reproduce($row);

            return ['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id,
                'effective_from' => now(), 'active_key' => hash('sha256', $row->monitored_service_id.'|'.$row->chart_type), 'decision_reason' => $reason];
        }, 'approved');
    }

    public function retire(ControlChartBaseline $baseline, User $actor, string $reason): ControlChartBaseline
    {
        return $this->transition($baseline, $actor, function ($row) use ($actor, $reason) {
            if ($row->status !== 'approved' || trim($reason) === '') {
                $this->invalid('Retirement requires an approved version and a reason.');
            }

            return ['status' => 'retired', 'retired_at' => now(), 'retired_by' => $actor->id, 'effective_to' => now(), 'active_key' => null, 'decision_reason' => $reason];
        }, 'retired');
    }

    public function reject(ControlChartBaseline $baseline, User $actor, string $reason): ControlChartBaseline
    {
        return $this->transition($baseline, $actor, function ($row) use ($actor, $reason) {
            if (! in_array($row->status, ['draft', 'reviewed'], true) || trim($reason) === '') {
                $this->invalid('Rejection requires a draft/reviewed version and a reason.');
            }

            return ['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->id, 'decision_reason' => $reason];
        }, 'rejected');
    }

    public function samples(ControlChartBaseline $baseline): array
    {
        return $baseline->samples()->orderBy('sequence')->get()->map(fn ($s) => $s->only(['service_check_id', 'sequence', 'included', 'exclusion_reasons', 'measurement']))->all();
    }

    public function reproduce(ControlChartBaseline $baseline): array
    {
        if ($baseline->calculation_version !== 'spc-phase1-r4a-v1' || $baseline->method_identifier !== ($baseline->chart_type === 'p_chart' ? 'pooled-binomial-proportion-v1' : 'individuals-moving-range-2-v1')) {
            throw new LogicException('Unsupported archived baseline method/version.');
        }
        $samples = $this->samples($baseline);
        if (! hash_equals($baseline->membership_digest, $this->digest($samples))) {
            throw new LogicException('Baseline membership integrity check failed.');
        }
        $result = $this->statistics->calculate($baseline->chart_type, $samples, $baseline->coverage_context['buckets']);
        if (array_diff_key($result['parameters'], $baseline->parameters) || array_diff_key($baseline->parameters, $result['parameters'])) {
            throw new LogicException('Baseline parameter schema does not match the archived method.');
        }
        foreach ($baseline->parameters as $key => $value) {
            $actual = $result['parameters'][$key] ?? null;
            if (($value === null) !== ($actual === null) || ($value !== null && abs($value - $actual) > 1e-10 * max(1, abs($value)))) {
                throw new LogicException('Baseline parameter reproduction failed.');
            }
        }

        return $result;
    }

    public function compatible(ControlChartBaseline $baseline): bool
    {
        $service = MonitoredService::find($baseline->monitored_service_id);

        return $service !== null && hash_equals($baseline->compatibility_fingerprint, $this->configuration->fingerprint($this->configuration->snapshot($service, $baseline->analysis_timezone, $baseline->aggregation_interval, $baseline->method_identifier)));
    }

    private function transition(ControlChartBaseline $baseline, User $actor, callable $change, string $event): ControlChartBaseline
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($baseline, $actor, $change, $event) {
            MonitoredService::query()->lockForUpdate()->findOrFail($baseline->monitored_service_id);
            $row = ControlChartBaseline::query()->lockForUpdate()->findOrFail($baseline->id);
            if (! $row->sealed_at) {
                $this->invalid('Incomplete baseline cannot transition.');
            }
            $updates = $change($row);
            DB::table('control_chart_baselines')->where('id', $row->id)->update($updates + ['updated_at' => now()]);
            $row->refresh();
            $this->record($event, $row, $actor);

            return $baseline->refresh();
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->fresh()?->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor->fresh())->authorize(self::PERMISSION);
    }

    private function digest(array $samples): string
    {
        // JSON float representation is normalized across database serialization.
        return hash('sha256', ResearchSnapshotEncoding::encode($samples));
    }

    private function record(string $event, ControlChartBaseline $baseline, User $actor): void
    {
        $this->audit->log('baseline.'.$event, $baseline, 'Phase I baseline '.$event, context: [
            'baseline_id' => $baseline->id, 'version' => $baseline->version, 'service_id' => $baseline->monitored_service_id,
            'chart_type' => $baseline->chart_type, 'baseline_start' => $baseline->baseline_start->toIso8601String(), 'baseline_end' => $baseline->baseline_end->toIso8601String(),
            'status' => $baseline->status, 'actor_id' => $actor->id,
        ], after: ['review_notes' => $baseline->review_notes, 'decision_reason' => $baseline->decision_reason, 'effective_from' => $baseline->effective_from?->toIso8601String(), 'effective_to' => $baseline->effective_to?->toIso8601String()], actor: $actor);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['baseline' => $message]);
    }
}
