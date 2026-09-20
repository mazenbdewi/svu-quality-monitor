<?php

namespace App\Services\Research;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Monitoring\MeasurementLimits;
use App\Services\Baselines\BaselineConfiguration;
use App\Services\Spc\PhaseTwoEvaluator;
use Symfony\Component\Process\Process;

class ResearchConfiguration
{
    public function snapshot(): array
    {
        $git = $this->git(['rev-parse', 'HEAD']);
        $status = $this->git(['status', '--porcelain']);
        $files = [];
        foreach (['app', 'config', 'database/migrations', 'database/seeders', 'routes', 'docs', 'docker', 'resources', 'lang', 'tests'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        foreach (['composer.lock', 'package-lock.json', 'Dockerfile', 'docker-compose.yml'] as $file) {
            if (is_file(base_path($file))) {
                $files[$file] = hash_file('sha256', base_path($file));
            }
        }
        ksort($files);
        $services = MonitoredService::orderBy('id')->get()->map(function ($s) {
            return $s->only(['id', 'name', 'is_active', 'check_interval_minutes', 'warning_response_ms', 'critical_response_ms', 'failure_confirmation_count', 'recovery_confirmation_count', 'sla_target_percent', 'sla_enabled', 'created_at']) + [
                'check_type' => $s->check_type->value, 'timeout_seconds' => $s->timeoutSeconds(),
                'configuration' => app(BaselineConfiguration::class)->snapshot($s, config('monitoring.spc.analysis_timezone'), 'hourly', 'individuals-moving-range-2-v1'),
            ];
        })->all();

        return [
            'snapshot_version' => 'research-freeze-r5-v1', 'generated_at' => now()->utc()->toIso8601String(),
            'code' => ['git_commit' => $git ?: (getenv('RESEARCH_GIT_COMMIT') ?: null), 'git_dirty' => $status === null ? null : $status !== '', 'source_sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)), 'source_files' => $files, 'image_git_note' => 'A commit alone does not identify an uncommitted working tree; compare source_sha256 and archive the snapshot.'],
            'clock' => ['utc_now' => now()->utc()->toIso8601String(), 'institution_local_now' => now()->setTimezone('Asia/Damascus')->toIso8601String(), 'application_timezone' => config('app.timezone'), 'php_timezone' => date_default_timezone_get(), 'analysis_timezone' => config('monitoring.spc.analysis_timezone')],
            'policies' => ['measurement' => 'R1 code-defined (not independently stamped on every raw check)', 'eligibility' => 'r1-research+r4a-record-cutoff-v1', 'coverage' => 'occupied automatic slots / expected slots after maintenance; unknown is not healthy; current-configuration estimate', 'phase_two_coverage' => 'observed-evaluator-state-one-collection-interval-v1', 'problematic' => '!is_success || is_slow', 'episode' => 'same baseline/chart/direction/mode; detection-gap-v1 (identified by R4B code and stored gap)', 'episode_gap_minutes' => config('monitoring.spc.episode_gap_minutes'), 'association_horizon_minutes' => config('monitoring.spc.association_horizon_minutes'), 'association' => 'earliest-within-horizon-v1', 'phase_two_calculation' => PhaseTwoEvaluator::VERSION, 'signal_rule' => PhaseTwoEvaluator::RULE, 'baseline_calculation' => 'spc-phase1-r4a-v1', 'exploratory_calculation' => 'spc-r3-v1', 'reliability' => 'Code-defined R2; measurement_context has semantic fields but no separate algorithm version', 'sla' => 'code version; stored target and period metrics', 'retention' => 'No study retention duration is implicitly approved; researcher must confirm preservation and backups.'],
            'runtime' => ['php_version' => PHP_VERSION, 'laravel_version' => app()->version(), 'queue_connection' => config('queue.default'), 'retry_after_seconds' => config('queue.connections.'.config('queue.default').'.retry_after'), 'monitoring_job_timeout_seconds' => MeasurementLimits::JOB_TIMEOUT_SECONDS, 'phase_two_job_timeout_seconds' => 60, 'spc_scheduler' => 'every minute', 'monitoring_scheduler' => 'every minute; per-service due interval', 'daily_spc_timezone' => config('monitoring.spc.analysis_timezone'), 'other_schedules_timezone' => config('app.timezone')],
            'services' => $services,
            'baselines' => ControlChartBaseline::orderBy('id')->get()->map(fn ($b) => $b->only(['id', 'monitored_service_id', 'chart_type', 'version', 'status', 'baseline_start', 'baseline_end', 'effective_from', 'effective_to', 'approved_at', 'analysis_timezone', 'aggregation_interval', 'calculation_version', 'eligibility_policy_version', 'compatibility_fingerprint', 'membership_digest', 'parameters']))->all(),
            'decisions_to_confirm' => ['Selected service IDs and monitoring permission', 'Monitoring cadence and resource budget (5 minutes is a proposal)', 'Pilot duration (7 days proposed, not formal Phase I)', 'Accept or change 30-minute gap / 60-minute horizon before data collection', 'Coverage acceptance and retention duration', 'Approved references for workflow validation; no automatic post-pilot baseline'],
        ];
    }

    private function git(array $args): ?string
    {
        try {
            $p = new Process(array_merge(['git'], $args), base_path());
            $p->run();

            return $p->isSuccessful() ? trim($p->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
