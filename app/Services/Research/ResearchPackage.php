<?php

namespace App\Services\Research;

use App\Models\AuditLog;
use App\Models\ControlChart;
use App\Models\ControlChartBaseline;
use App\Models\ControlChartPoint;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use App\Models\SpcIncidentLink;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcResearchRun;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\MonitoringCoverageCalculator;
use App\Services\SpcResearchData;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Explicit CLI archive: read-only database access, no recalculation or research-run mutation. */
class ResearchPackage
{
    public function export(Carbon $start, Carbon $end, string $directory): array
    {
        $start = $start->copy()->utc();
        $end = $end->copy()->utc();
        $cutoff = now()->utc();
        if ($end->lte($start) || $end->gt($cutoff) || file_exists($directory)) {
            throw new InvalidArgumentException('Use a closed positive UTC interval and a new output directory.');
        }
        if (! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create package directory.');
        }
        $counts = [];
        $hashes = [];
        $write = function (string $name, array $rows) use ($directory, &$counts, &$hashes) {
            $bytes = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($directory.'/'.$name, $bytes) === false) {
                throw new \RuntimeException('Export write failed.');
            }
            $counts[$name] = array_is_list($rows) ? count($rows) : 1;
            $hashes[$name] = hash('sha256', $bytes);
        };
        $csv = function (string $name, array $rows) use ($directory, &$counts, &$hashes) {
            $f = fopen($directory.'/'.$name, 'x');
            if (! $f) {
                throw new \RuntimeException('CSV write failed.');
            }
            if ($rows) {
                fputcsv($f, array_keys($rows[0]), ',', '"', '');
            }
            foreach ($rows as $row) {
                fputcsv($f, array_map(function ($v) {
                    if (is_array($v)) {
                        return json_encode($v, JSON_THROW_ON_ERROR);
                    }
                    if (is_bool($v)) {
                        return (int) $v;
                    }
                    if (is_string($v) && preg_match('/^[=+@\-]/', $v)) {
                        return "'".$v;
                    }

                    return $v;
                }, array_values($row)), ',', '"', '');
            }
            fclose($f);
            $counts[$name] = count($rows);
            $hashes[$name] = hash_file('sha256', $directory.'/'.$name);
        };

        return DB::transaction(function () use ($start, $end, $cutoff, $write, $csv, &$counts, &$hashes) {
            $configuration = app(ResearchConfiguration::class)->snapshot();
            $write('configuration.json', $configuration);
            $followupMinutes = max((int) config('monitoring.spc.association_horizon_minutes', 60), (int) SpcResearchRun::where('period_start', '<', $end)->where('period_end', '>', $start)->where('data_cutoff', '<=', $cutoff)->max('horizon_minutes'));
            $followupEnd = $end->copy()->addMinutes($followupMinutes)->min($cutoff);
            $services = MonitoredService::orderBy('id')->get();
            $write('services.json', $configuration['services']);
            $raw = [];
            $eligible = [];
            $exclusions = [];
            $coverage = [];
            foreach ($services as $service) {
                $coverage[] = ['service_id' => $service->id, 'basis' => 'current configuration; freeze snapshot and segment changes'] + app(MonitoringCoverageCalculator::class)->calculate($service, $start, $end);
                $binary = app(SpcResearchData::class)->checks($service, $start, $end)->pluck('id')->flip();
                $latency = app(SpcResearchData::class)->checks($service, $start, $end, true)->pluck('id')->flip();
                foreach ($service->serviceChecks()->where('checked_at', '>=', $start)->where('checked_at', '<', $end)->where('created_at', '<=', $cutoff)->orderBy('checked_at')->orderBy('id')->get() as $c) {
                    $row = $c->only(['id', 'monitored_service_id', 'checked_at', 'created_at', 'updated_at', 'source', 'check_type', 'is_success', 'is_slow', 'performance_status', 'response_time_ms', 'status_code', 'is_during_maintenance', 'maintenance_window_id']);
                    foreach (['checked_at', 'created_at', 'updated_at'] as $k) {
                        $row[$k] = $c->$k?->toIso8601String();
                    }
                    $row += ['problematic' => $c->is_problematic, 'binary_eligible' => $binary->has($c->id), 'latency_eligible' => $latency->has($c->id), 'synthetic' => (bool) data_get($c->metadata, 'is_synthetic'), 'diagnostic' => (bool) data_get($c->metadata, 'is_diagnostic')];
                    $raw[] = $row;
                    if ($row['binary_eligible']) {
                        $eligible[] = $row;
                    }
                    if (! $row['latency_eligible']) {
                        $reasons = [];
                        if ($c->source !== 'automatic') {
                            $reasons[] = 'manual_or_unknown_source';
                        }
                        if (! in_array($c->check_type, ['http', 'api'], true)) {
                            $reasons[] = 'non_http_api';
                        }
                        if ($row['synthetic'] || $row['diagnostic']) {
                            $reasons[] = 'synthetic_or_diagnostic';
                        }
                        if ($c->is_during_maintenance || (! $binary->has($c->id) && ! $reasons)) {
                            $reasons[] = 'maintenance';
                        }
                        if (! $c->is_success) {
                            $reasons[] = 'functional_failure_latency_excluded';
                        }
                        if ($c->response_time_ms === null || $c->response_time_ms < 0) {
                            $reasons[] = 'invalid_latency';
                        }
                        $exclusions[] = ['check_id' => $c->id, 'binary_eligible' => $row['binary_eligible'], 'latency_eligible' => false, 'reasons' => $reasons];
                    }
                }
            }
            $write('coverage.json', $coverage);
            $write('checks.json', $raw);
            $csv('minitab_eligible_checks.csv', $eligible);
            $write('exclusions.json', $exclusions);
            $write('incidents.json', ServiceIncident::where('started_at', '<', $followupEnd)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))->orderBy('id')->get()->map(fn ($i) => $i->only(['id', 'monitored_service_id', 'started_at', 'confirmed_at', 'ended_at', 'resolved_at', 'created_at', 'updated_at', 'status', 'failure_count']))->all());
            $write('maintenance.json', MaintenanceWindow::with('monitoredServices:id')->where('starts_at', '<', $cutoff)->get()->map(fn ($w) => $w->only(['id', 'starts_at', 'ends_at', 'applies_to_all_services', 'created_at', 'updated_at']) + ['service_ids' => $w->monitoredServices->pluck('id')->all()])->all());
            foreach (['reliability.json' => ReliabilityMetric::class, 'sla.json' => SlaMetric::class] as $name => $model) {
                $write($name, $model::where('period_start', '<', $end)->where('period_end', '>', $start)->orderBy('id')->get()->toArray());
            }
            $charts = ControlChart::where('period_start', '<', $end)->where('period_end', '>', $start)->orderBy('id')->get();
            $write('exploratory_charts.json', $charts->map(fn ($c) => Arr::except($c->toArray(), ['notes']))->all());
            $write('exploratory_points.json', ControlChartPoint::whereIn('control_chart_id', $charts->pluck('id'))->orderBy('id')->get()->toArray());
            $baselines = ControlChartBaseline::whereNotNull('approved_at')->where('approved_at', '<=', $cutoff)->orderBy('id')->get();
            $write('baselines.json', $baselines->map(fn ($b) => $b->only(['id', 'monitored_service_id', 'chart_type', 'version', 'status', 'baseline_start', 'baseline_end', 'data_cutoff', 'effective_from', 'effective_to', 'approved_at', 'parameters', 'configuration_snapshot', 'compatibility_fingerprint', 'membership_digest', 'calculation_version', 'eligibility_policy_version', 'analysis_timezone', 'aggregation_interval', 'coverage_context']))->all());
            foreach ($baselines as $baseline) {
                $engine = app(PhaseOneBaselineService::class);
                $write('baseline_'.$baseline->id.'_membership.json', $engine->samples($baseline));
                $csv('minitab_baseline_'.$baseline->id.'_points.csv', $engine->reproduce($baseline)['points']);
                $csv('minitab_baseline_'.$baseline->id.'_members.csv', array_map(fn ($s) => ['check_id' => $s['service_check_id'], 'sequence' => $s['sequence'], 'included' => $s['included']] + $s['measurement'], $engine->samples($baseline)));
            }
            foreach (['evaluations' => SpcMonitoringEvaluation::class, 'points' => SpcMonitoringPoint::class, 'signals' => SpcSignal::class, 'episodes' => SpcSignalEpisode::class] as $name => $model) {
                foreach (['live', 'retrospective'] as $mode) {
                    $time = $name === 'episodes' ? 'first_detected_at' : 'detected_at';
                    $rows = $model::where('mode', $mode)->where($time, '<=', $cutoff)->orderBy('id')->get()->toArray();
                    $write($mode.'_'.$name.'.json', $rows);
                    if ($name === 'points') {
                        $csv('minitab_'.$mode.'_phase_two.csv', $rows);
                    }
                }
            }
            $runs = SpcResearchRun::where('period_start', '<', $end)->where('period_end', '>', $start)->where('data_cutoff', '<=', $cutoff)->orderBy('id')->get();
            foreach (['live', 'retrospective'] as $mode) {
                $selected = $runs->where('mode', $mode);
                $write($mode.'_evaluation_summary.json', $selected->values()->toArray());
                $write($mode.'_incident_links.json', SpcIncidentLink::whereIn('research_run_id', $selected->pluck('id'))->orderBy('id')->get()->toArray());
            }
            $write('audit_index.json', AuditLog::where('created_at', '>=', $start)->where('created_at', '<=', $cutoff)->orderBy('id')->get(['id', 'event', 'auditable_type', 'auditable_id', 'actor_id', 'created_at'])->toArray());
            $manifest = ['generated_at' => $cutoff->toIso8601String(), 'requested_period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'boundary' => '[start,end)'], 'followup_context_end' => $followupEnd->toIso8601String(), 'timezone' => 'UTC', 'code' => $configuration['code'], 'policies' => $configuration['policies'], 'baseline_versions' => $baselines->map(fn ($b) => ['id' => $b->id, 'version' => $b->version])->all(), 'row_counts' => $counts, 'sha256' => $hashes, 'scope' => 'Raw checks use requested period. Incidents include available association follow-up and maintenance includes historical context. Baseline and Phase II history through export cutoff are included for context. Stored overlapping metrics/runs retain their own periods. No result is recalculated. Empty CSV has no rows. Configuration/coverage are export-time views, not proof of unrecorded historical settings.', 'complete' => true];
            $write('manifest.json', $manifest);

            return $manifest;
        });
    }
}
