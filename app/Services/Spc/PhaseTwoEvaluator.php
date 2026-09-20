<?php

namespace App\Services\Spc;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Services\Baselines\PhaseOneBaselineService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PhaseTwoEvaluator
{
    public const VERSION = 'phase2-r4b-v1';

    public const RULE = 'point-outside-limit-v1';

    public function __construct(private PhaseTwoPopulation $population, private PhaseOneBaselineService $baselines) {}

    public function evaluate(MonitoredService $service, string $mode = 'live', ?Carbon $cutoff = null, ?int $baselineId = null, array $executionContext = []): array
    {
        if (! in_array($mode, ['live', 'retrospective'], true) || ($mode === 'live' && $cutoff !== null)) {
            throw new InvalidArgumentException('Live evaluation uses the actual clock; historical cutoffs require retrospective mode.');
        }
        $clock = now()->utc()->startOfSecond();
        $cutoff = $cutoff?->copy()->utc()->startOfSecond() ?? $clock->copy();
        if ($cutoff->gt($clock)) {
            throw new InvalidArgumentException('Future cutoff is not allowed.');
        }

        return DB::transaction(function () use ($service, $mode, $cutoff, $clock, $baselineId, $executionContext) {
            // Serialize research work on research rows, not the operational service/incident lock.
            ControlChartBaseline::where('monitored_service_id', $service->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $service = MonitoredService::findOrFail($service->id);
            // A queued/concurrent evaluator may wait on this lock. Never backdate that delay.
            $clock = now()->utc()->startOfSecond();
            if ($mode === 'live') {
                $cutoff = $clock->copy();
            }
            if ($mode === 'retrospective' && SpcMonitoringEvaluation::where('service_id', $service->id)->where('mode', $mode)->where('detected_at', '>', $cutoff)->exists()) {
                throw new InvalidArgumentException('Retrospective replay must advance in chronological order.');
            }
            $results = [];
            foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
                $query = ControlChartBaseline::where('monitored_service_id', $service->id)->where('chart_type', $type)->whereNotNull('approved_at')
                    ->where('approved_at', '<=', $cutoff)->where('effective_from', '<=', $cutoff)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $cutoff));
                $query->whereIn('status', $mode === 'live' ? ['approved'] : ['approved', 'retired']);
                if ($baselineId !== null) {
                    $query->whereKey($baselineId);
                }
                $baseline = $query->orderByDesc('version')->first();
                $detected = $mode === 'live' ? $clock->copy() : $cutoff->copy();
                $status = 'no_approved_baseline';
                $points = [];
                $start = null;
                if ($baseline) {
                    $start = $baseline->baseline_end->copy()->max($baseline->effective_from);
                    $compatible = $this->baselines->compatible($baseline);
                    $previousMismatch = SpcMonitoringEvaluation::where('baseline_id', $baseline->id)->where('mode', $mode)->where('status', 'baseline_incompatible')->where('detected_at', '<=', $cutoff)->exists();
                    $status = $compatible && ! $previousMismatch ? 'awaiting_data' : 'baseline_incompatible';
                    if ($status !== 'baseline_incompatible' && $start->lte($cutoff)) {
                        $this->baselines->reproduce($baseline);
                        $fresh = $this->population->checks($service, $start->copy()->max($cutoff->copy()->subMinutes(max(1, $service->check_interval_minutes))), $cutoff->copy()->addSecond(), $cutoff);
                        $status = $fresh->isEmpty() ? 'awaiting_data' : 'active';
                        $points = $type === 'p_chart' ? $this->pPoints($service, $baseline, $start, $cutoff, $mode) : $this->latencyPoints($service, $baseline, $start, $cutoff, $mode);
                        if ($type === 'p_chart') {
                            $last = $points ? end($points)['status'] : SpcMonitoringPoint::where('baseline_id', $baseline->id)->where('mode', $mode)->where('observed_at', '<', $cutoff)->orderByDesc('observed_at')->value('status');
                            if ($last !== 'eligible') {
                                $status = $last === 'insufficient_coverage' || $last === 'missing' ? 'insufficient_coverage' : 'awaiting_closed_subgroup';
                            }
                        }
                    }
                }
                $evaluation = SpcMonitoringEvaluation::create([
                    'service_id' => $service->id, 'baseline_id' => $baseline?->id, 'chart_type' => $type, 'mode' => $mode, 'status' => $status,
                    'detected_at' => $detected, 'data_cutoff' => $cutoff,
                    'exposure_until' => $status === 'active' ? $detected->copy()->addMinutes(max(1, $service->check_interval_minutes)) : null,
                    'calculation_version' => self::VERSION,
                    'context' => ['monitoring_start' => $start?->toIso8601String(), 'baseline_version' => $baseline?->version, 'compatibility_fingerprint' => $baseline?->compatibility_fingerprint,
                        'executed_at' => $clock->toIso8601String(), 'rule_version' => self::RULE, 'new_points' => count($points), 'job_timing' => Arr::only($executionContext, ['job_enqueued_at', 'job_started_at', 'queue_delay_seconds']), 'exposure_policy' => 'observed-evaluator-state-one-collection-interval-v1'],
                ]);
                foreach ($points as $point) {
                    $this->savePoint($baseline, $evaluation, $point);
                }
                $results[] = $evaluation;
            }
            SpcSignalEpisode::where('service_id', $service->id)->where('mode', $mode)->whereNull('closed_at')->get()->each(function ($episode) use ($cutoff) {
                $end = $episode->last_detected_at->copy()->addMinutes($episode->gap_minutes);
                if ($end->lt($cutoff)) {
                    DB::table('spc_signal_episodes')->where('id', $episode->id)->update(['closed_at' => $end, 'updated_at' => now()]);
                }
            });

            return $results;
        }, 3);
    }

    private function latencyPoints($service, $baseline, Carbon $start, Carbon $cutoff, string $mode): array
    {
        $checks = $this->population->checks($service, $start, $cutoff->copy()->addSecond(), $cutoff, true);
        $frozen = SpcMonitoringPoint::where('baseline_id', $baseline->id)->where('mode', $mode)->where('rule_version', self::RULE)->where('detected_at', '<=', $cutoff)->orderBy('observed_at')->orderBy('id')->get();
        $existing = $frozen->pluck('source_key')->flip();
        // Preserve already-observed values even if operational records later change or disappear.
        $byId = $checks->keyBy('id');
        foreach ($frozen as $point) {
            $id = $point->context['source_check_id'];
            $snapshot = new ServiceCheck;
            $snapshot->forceFill(['id' => $id, 'checked_at' => $point->observed_at, 'response_time_ms' => $point->context['response_time_ms'],
                'created_at' => $point->context['available_at'], 'performance_status' => $point->context['fixed_performance_status']]);
            $byId->put($id, $snapshot);
        }
        $checks = $byId->sort(fn ($a, $b) => $a->checked_at->getTimestamp() <=> $b->checked_at->getTimestamp() ?: $a->id <=> $b->id)->values();
        $latest = $frozen->where('status', '!=', 'late_out_of_order')->last();
        $previous = null;
        $points = [];
        foreach ($checks as $check) {
            $key = 'check:'.$check->id;
            $isMr = $baseline->chart_type === 'mr_chart';
            $late = $isMr && ! $existing->has($key) && $latest && ($check->checked_at->lt($latest->observed_at) || ($check->checked_at->eq($latest->observed_at) && $check->id < $latest->context['source_check_id']));
            $usablePrevious = $late ? null : $previous;
            if ($isMr && $previous && SpcMonitoringEvaluation::where('service_id', $service->id)->where('chart_type', 'mr_chart')->where('mode', $mode)->where('status', '!=', 'active')->where('detected_at', '>', $previous->checked_at)->where('detected_at', '<=', $check->checked_at)->exists()) {
                $usablePrevious = null;
            }
            if (! $existing->has($key)) {
                $value = $isMr ? ($usablePrevious ? abs($check->response_time_ms - $usablePrevious->response_time_ms) : null) : (float) $check->response_time_ms;
                $points[] = ['source_key' => $key, 'observed_at' => $check->checked_at, 'value' => $value,
                    'center_line' => $baseline->parameters['cl'], 'upper_control_limit' => $baseline->parameters['ucl'], 'lower_control_limit' => $baseline->parameters['lcl'],
                    'subgroup_size' => null, 'status' => $late ? 'late_out_of_order' : ($value === null ? 'awaiting_previous' : 'eligible'),
                    'context' => ['source_check_id' => $check->id, 'previous_check_id' => $usablePrevious?->id, 'previous_value' => $usablePrevious?->response_time_ms,
                        'response_time_ms' => $check->response_time_ms, 'available_at' => $check->created_at->toIso8601String(),
                        'fixed_performance_status' => $check->performance_status, 'fixed_threshold_available_at' => $check->created_at->toIso8601String()]];
            }
            if (! $late && ! $frozen->contains(fn ($p) => $p->source_key === $key && $p->status === 'late_out_of_order')) {
                $previous = $check;
            }
        }

        return $points;
    }

    private function pPoints($service, $baseline, Carbon $start, Carbon $cutoff, string $mode): array
    {
        $daily = $baseline->aggregation_interval === 'daily';
        $cursor = $start->copy()->setTimezone($baseline->analysis_timezone);
        $cursor = $daily ? $cursor->startOfDay() : $cursor->startOfHour();
        if ($cursor->lt($start)) {
            $cursor = $daily ? $cursor->addDay() : $cursor->addHour();
        }
        $existing = SpcMonitoringPoint::where('baseline_id', $baseline->id)->where('mode', $mode)->where('rule_version', self::RULE)->pluck('source_key')->flip();
        $points = [];
        $p0 = $baseline->parameters['p0'];
        while (true) {
            $end = $daily ? $cursor->copy()->addDay() : $cursor->copy()->addHour();
            if ($end->gt($cutoff) || ($baseline->effective_to && $end->gt($baseline->effective_to))) {
                break;
            }
            $a = $cursor->copy()->utc();
            $b = $end->copy()->utc();
            $key = 'bucket:'.$a->timestamp;
            if (! $existing->has($key)) {
                $group = $this->population->bucket($service, $a, $b, $cutoff);
                $sigma = $group['n'] > 0 && $p0 !== null ? sqrt($p0 * (1 - $p0) / $group['n']) : null;
                $points[] = ['source_key' => $key, 'observed_at' => $a, 'value' => $group['n'] ? $group['d'] / $group['n'] : null,
                    'center_line' => $p0, 'upper_control_limit' => $sigma === null ? null : min(1, $p0 + 3 * $sigma),
                    'lower_control_limit' => $sigma === null ? null : max(0, $p0 - 3 * $sigma), 'subgroup_size' => $group['n'], 'status' => $group['status'], 'context' => $group];
            }
            $cursor = $end;
        }

        return $points;
    }

    private function savePoint(ControlChartBaseline $baseline, SpcMonitoringEvaluation $evaluation, array $data): void
    {
        $identity = hash('sha256', implode('|', [$baseline->id, $baseline->chart_type, $data['source_key'], $evaluation->mode, self::RULE]));
        if (SpcMonitoringPoint::where('identity', $identity)->exists()) {
            return;
        }
        $point = SpcMonitoringPoint::create($data + [
            'identity' => $identity, 'evaluation_id' => $evaluation->id, 'baseline_id' => $baseline->id, 'baseline_version' => $baseline->version,
            'service_id' => $baseline->monitored_service_id, 'chart_type' => $baseline->chart_type, 'mode' => $evaluation->mode,
            'detected_at' => $evaluation->detected_at, 'data_cutoff' => $evaluation->data_cutoff,
            'aggregation_interval' => $baseline->aggregation_interval, 'calculation_version' => self::VERSION, 'rule_version' => self::RULE,
        ]);
        if ($point->status !== 'eligible' || $point->value === null) {
            return;
        }
        $direction = $point->upper_control_limit !== null && $point->value > $point->upper_control_limit ? 'upper' : ($point->lower_control_limit !== null && $point->value < $point->lower_control_limit ? 'lower' : null);
        if ($direction === null) {
            return;
        }
        $gap = max(1, (int) config('monitoring.spc.episode_gap_minutes', 30));
        $episode = SpcSignalEpisode::where('baseline_id', $baseline->id)->where('mode', $point->mode)->where('direction', $direction)->where('rule_version', self::RULE)->where('gap_minutes', $gap)
            ->whereNull('closed_at')->where('last_detected_at', '<=', $point->detected_at)->where('last_detected_at', '>=', $point->detected_at->copy()->subMinutes($gap))->orderByDesc('id')->first();
        if ($episode) {
            DB::table('spc_signal_episodes')->where('id', $episode->id)->update(['last_detected_at' => $point->detected_at, 'signal_count' => $episode->signal_count + 1, 'updated_at' => now()]);
        } else {
            $episode = SpcSignalEpisode::create(['baseline_id' => $baseline->id, 'baseline_version' => $baseline->version, 'service_id' => $baseline->monitored_service_id,
                'chart_type' => $baseline->chart_type, 'mode' => $point->mode, 'direction' => $direction, 'rule_version' => self::RULE, 'gap_minutes' => $gap,
                'episode_started_at' => $point->observed_at, 'first_detected_at' => $point->detected_at, 'last_detected_at' => $point->detected_at, 'signal_count' => 1]);
        }
        SpcSignal::create($point->only(['baseline_id', 'baseline_version', 'service_id', 'chart_type', 'mode', 'observed_at', 'detected_at', 'data_cutoff', 'value', 'center_line', 'upper_control_limit', 'lower_control_limit', 'aggregation_interval', 'subgroup_size', 'calculation_version', 'rule_version', 'context']) + [
            'identity' => $identity, 'point_id' => $point->id, 'episode_id' => $episode->id, 'direction' => $direction, 'rule_code' => 'beyond_3sigma_limit', 'status' => 'recorded',
        ]);
    }
}
