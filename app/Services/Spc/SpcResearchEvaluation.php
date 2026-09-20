<?php

namespace App\Services\Spc;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceIncident;
use App\Models\SpcIncidentLink;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcResearchRun;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Services\MaintenanceWindowService;
use App\Services\ReliabilityTimeIntervals;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Linking is retrospective analysis of already-recorded warnings; never called by the detector. */
class SpcResearchEvaluation
{
    public function __construct(private ReliabilityTimeIntervals $intervals, private MaintenanceWindowService $maintenance) {}

    public function run(Carbon $start, Carbon $end, string $mode = 'live', ?Carbon $cutoff = null, ?int $serviceId = null, ?string $chartType = null): SpcResearchRun
    {
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();
        $cutoff = $cutoff?->copy()->utc() ?? now()->utc();
        if ($end->lte($start) || $cutoff->gt(now()) || ! in_array($mode, ['live', 'retrospective'], true)) {
            throw new InvalidArgumentException('Invalid research interval, cutoff or mode.');
        }
        $horizon = max(1, (int) config('monitoring.spc.association_horizon_minutes', 60));
        $gap = max(1, (int) config('monitoring.spc.episode_gap_minutes', 30));

        return DB::transaction(function () use ($start, $end, $mode, $cutoff, $serviceId, $chartType, $horizon, $gap) {
            $baselines = ControlChartBaseline::whereNotNull('approved_at')->where('approved_at', '<=', $cutoff)->when($serviceId, fn ($q) => $q->where('monitored_service_id', $serviceId))->when($chartType, fn ($q) => $q->where('chart_type', $chartType))->get();
            $groups = [];
            $datasets = [];
            $links = [];
            $anyIncidents = [];
            $anyMatched = [];
            foreach ($baselines as $baseline) {
                $service = $baseline->monitoredService;
                if (! $service) {
                    continue;
                }
                // Load full service only for historical maintenance applicability.
                $service = MonitoredService::find($baseline->monitored_service_id);
                $exposure = $this->exposure($baseline, $mode, $cutoff);
                $firstExposure = $exposure->first()['start'] ?? null;
                $episodes = SpcSignalEpisode::where('baseline_id', $baseline->id)->where('mode', $mode)->where('rule_version', PhaseTwoEvaluator::RULE)->where('gap_minutes', $gap)
                    ->where('first_detected_at', '>=', $start->copy()->subMinutes($horizon))->where('first_detected_at', '<', $end)->where('first_detected_at', '<=', $cutoff)->orderBy('first_detected_at')->orderBy('id')->get();
                $incidents = ServiceIncident::where('monitored_service_id', $baseline->monitored_service_id)->whereNotNull('confirmed_at')->where('confirmed_at', '<=', $cutoff)->where('created_at', '<=', $cutoff)
                    ->where('started_at', '>=', $start)->where('started_at', '<', $end->copy()->addMinutes($horizon))->where('started_at', '<=', $cutoff)->orderBy('started_at')->get();
                $eligible = [];
                $excluded = [];
                $incidentRows = [];
                $episodeRows = [];
                $matchedEpisodeIds = [];
                $matchedIncidentIds = [];
                $leads = [];
                foreach ($incidents as $incident) {
                    $at = $incident->started_at;
                    $reason = null;
                    if ($this->maintenance->mergedOverlapIntervals($service, $at, $at->copy()->addSecond())->isNotEmpty()) {
                        $reason = 'planned_maintenance';
                    } elseif (! $firstExposure || ! $this->at($exposure, $at)) {
                        $reason = 'outside_observed_phase_two';
                    } elseif (! $this->covers($exposure, $at->copy()->subMinutes($horizon)->max($firstExposure), $at)) {
                        $reason = 'incomplete_prior_observation';
                    }
                    if ($reason) {
                        $excluded[] = ['incident_id' => $incident->id, 'reason' => $reason];

                        continue;
                    }
                    $inDenominator = $at->lt($end);
                    if ($inDenominator) {
                        $eligible[] = $incident->id;
                        $anyIncidents[$incident->id] = true;
                    }
                    $candidates = $episodes->filter(fn ($e) => $e->first_detected_at->lt($at) && $e->first_detected_at->copy()->addMinutes($horizon)->gte($at) && $this->at($exposure, $e->first_detected_at))->values();
                    $earliest = $candidates->first();
                    $nearest = $candidates->last();
                    foreach ($candidates as $episode) {
                        $seconds = (int) $episode->first_detected_at->diffInSeconds($at);
                        $links[] = ['incident_id' => $incident->id, 'episode_id' => $episode->id, 'baseline_id' => $baseline->id, 'chart_type' => $baseline->chart_type,
                            'is_primary' => $episode->id === $earliest->id, 'is_nearest' => $episode->id === $nearest->id, 'lead_seconds' => $seconds,
                            'context' => ['incident_started_at' => $at->toIso8601String(), 'confirmed_at' => $incident->confirmed_at->toIso8601String(), 'first_detected_at' => $episode->first_detected_at->toIso8601String(), 'mode' => $mode]];
                        $matchedEpisodeIds[$episode->id] = true;
                    }
                    if ($earliest && $inDenominator) {
                        $matchedIncidentIds[] = $incident->id;
                        $anyMatched[$incident->id] = true;
                        $leads[] = (int) $earliest->first_detected_at->diffInSeconds($at);
                    }
                    $fixed = SpcMonitoringPoint::where('baseline_id', $baseline->id)->where('mode', $mode)->where('detected_at', '<=', $cutoff)->orderBy('detected_at')->get()
                        ->filter(fn ($p) => in_array(data_get($p->context, 'fixed_performance_status'), ['warning', 'critical'], true) && Carbon::parse($p->context['fixed_threshold_available_at'])->lt($at) && Carbon::parse($p->context['fixed_threshold_available_at'])->gte($at->copy()->subMinutes($horizon)))
                        ->sortBy(fn ($p) => $p->context['fixed_threshold_available_at'])->first();
                    $incidentRows[] = ['incident_id' => $incident->id, 'in_recall_denominator' => $inDenominator, 'status' => $earliest ? 'matched' : 'incident_without_prior_spc_signal',
                        'earliest_episode_id' => $earliest?->id, 'nearest_episode_id' => $nearest?->id,
                        'timeline' => ['baseline_active' => $baseline->effective_from->toIso8601String(), 'spc_first_detected' => $earliest?->first_detected_at->toIso8601String(),
                            'fixed_threshold_available' => $fixed ? $fixed->context['fixed_threshold_available_at'] : null,
                            'incident_started' => $at->toIso8601String(), 'incident_confirmed' => $incident->confirmed_at->toIso8601String(),
                            'recovered' => $incident->ended_at && $incident->ended_at->lte($cutoff) ? $incident->ended_at->toIso8601String() : null]];
                }
                $mature = 0;
                $matched = 0;
                $unmatched = 0;
                $censored = 0;
                foreach ($episodes->filter(fn ($e) => $e->first_detected_at->gte($start)) as $episode) {
                    $deadline = $episode->first_detected_at->copy()->addMinutes($horizon);
                    $complete = $deadline->lte($cutoff) && $this->covers($exposure, $episode->first_detected_at, $deadline);
                    if (! $complete) {
                        $label = 'censored_incomplete_followup';
                        $censored++;
                    } else {
                        $mature++;
                        $label = isset($matchedEpisodeIds[$episode->id]) ? 'matched' : 'unmatched_signal';
                        $label === 'matched' ? $matched++ : $unmatched++;
                    }
                    $episodeRows[] = ['episode_id' => $episode->id, 'first_detected_at' => $episode->first_detected_at->toIso8601String(), 'status' => $label,
                        // Count only immutable signals available by this run cutoff, not a later mutable aggregate.
                        'signal_count' => SpcSignal::where('episode_id', $episode->id)->where('detected_at', '<=', $cutoff)->count()];
                }
                sort($leads);
                $n = count($leads);
                $groups[] = ['service_id' => $baseline->monitored_service_id, 'chart_type' => $baseline->chart_type, 'baseline_id' => $baseline->id, 'baseline_version' => $baseline->version,
                    'total_eligible_incidents' => count($eligible), 'incidents_with_prior_episode' => count($matchedIncidentIds), 'incidents_without_prior_signal' => count($eligible) - count($matchedIncidentIds),
                    'total_episodes' => count($episodeRows), 'eligible_episodes' => $mature, 'matched_episodes' => $matched, 'unmatched_episodes' => $unmatched, 'censored_episodes' => $censored,
                    'recall' => count($eligible) ? count($matchedIncidentIds) / count($eligible) : null, 'precision' => $mature ? $matched / $mature : null,
                    'mean_lead_seconds' => $n ? array_sum($leads) / $n : null, 'median_lead_seconds' => $n ? ($leads[(int) floor(($n - 1) / 2)] + $leads[(int) floor($n / 2)]) / 2 : null,
                    'min_lead_seconds' => $n ? min($leads) : null, 'max_lead_seconds' => $n ? max($leads) : null];
                $datasets[] = ['baseline_id' => $baseline->id, 'incidents' => $incidentRows, 'excluded_incidents' => $excluded, 'episodes' => $episodeRows,
                    'observed_exposure' => $exposure->map(fn ($s) => ['start' => $s['start']->toIso8601String(), 'end' => $s['end']->toIso8601String()])->all()];
            }
            $run = SpcResearchRun::create(['mode' => $mode, 'period_start' => $start, 'period_end' => $end, 'data_cutoff' => $cutoff,
                'evaluation_version' => 'spc-assessment-r4b-v1', 'association_policy_version' => 'earliest-within-horizon-v1', 'horizon_minutes' => $horizon, 'episode_gap_minutes' => $gap,
                'baseline_versions' => $baselines->map(fn ($b) => ['id' => $b->id, 'version' => $b->version])->all(),
                'metrics' => ['groups' => $groups, 'any_spc' => ['eligible_incidents' => count($anyIncidents), 'incidents_with_prior_episode' => count($anyMatched), 'recall' => count($anyIncidents) ? count($anyMatched) / count($anyIncidents) : null]],
                'dataset' => $datasets]);
            foreach ($links as $link) {
                SpcIncidentLink::create($link + ['research_run_id' => $run->id]);
            }

            return $run;
        });
    }

    private function exposure($baseline, string $mode, Carbon $cutoff): Collection
    {
        $evaluations = SpcMonitoringEvaluation::where('service_id', $baseline->monitored_service_id)->where('chart_type', $baseline->chart_type)->where('mode', $mode)->where('calculation_version', PhaseTwoEvaluator::VERSION)->where('detected_at', '<=', $cutoff)->orderBy('detected_at')->orderBy('id')->get();
        $segments = collect();
        foreach ($evaluations as $i => $e) {
            if ((int) $e->baseline_id !== (int) $baseline->id || $e->status !== 'active' || ! $e->exposure_until) {
                continue;
            }
            $end = $e->exposure_until->copy()->min($cutoff);
            if (isset($evaluations[$i + 1])) {
                $end = $end->min($evaluations[$i + 1]->detected_at);
            }
            if ($baseline->effective_to) {
                $end = $end->min($baseline->effective_to);
            }
            if ($end->gt($e->detected_at)) {
                $segments->push(['start' => $e->detected_at, 'end' => $end]);
            }
        }
        $merged = $this->intervals->union($segments);
        if ($merged->isEmpty()) {
            return $merged;
        }
        $service = MonitoredService::findOrFail($baseline->monitored_service_id);

        return $this->intervals->subtract($merged, $this->maintenance->mergedOverlapIntervals($service, $merged->first()['start'], $cutoff));
    }

    private function at(Collection $segments, Carbon $at): bool
    {
        return $segments->contains(fn ($s) => $at->gte($s['start']) && $at->lt($s['end']));
    }

    private function covers(Collection $segments, Carbon $from, Carbon $to): bool
    {
        return $segments->contains(fn ($s) => $s['start']->lte($from) && $s['end']->gte($to));
    }
}
