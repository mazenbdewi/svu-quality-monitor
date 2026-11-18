<?php

namespace App\Services;

use App\Models\BaselinePreparationRun;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    private const SCHEDULER_HEARTBEAT_KEY = 'system-health:scheduler:last-heartbeat';

    private const QUEUE_HEARTBEAT_KEY = 'system-health:queue:last-heartbeat';

    private const QUEUE_LAST_SUCCESS_KEY = 'system-health:queue:last-success';

    private const QUEUE_LAST_FAILURE_KEY = 'system-health:queue:last-failure';

    private const SLO_QUEUE_HEARTBEAT_KEY = 'system-health:slo-queue:last-heartbeat';

    private const BASELINE_QUEUE_HEARTBEAT_KEY = 'system-health:baseline-queue:last-heartbeat';

    private const BASELINE_QUEUE_EXPECTED_KEY = 'system-health:baseline-queue:expected-since';

    public function recordSchedulerHeartbeat(): void
    {
        $this->storeTimestamp(self::SCHEDULER_HEARTBEAT_KEY);
    }

    public function recordQueueHeartbeat(): void
    {
        $this->recordThrottledHeartbeat(self::QUEUE_HEARTBEAT_KEY);
    }

    public function recordSloQueueHeartbeat(): void
    {
        $this->recordThrottledHeartbeat(self::SLO_QUEUE_HEARTBEAT_KEY);
    }

    public function recordBaselineQueueHeartbeat(): void
    {
        $this->expectBaselineQueue();
        $this->recordThrottledHeartbeat(self::BASELINE_QUEUE_HEARTBEAT_KEY);
    }

    public function expectBaselineQueue(): void
    {
        if ($this->timestamp(self::BASELINE_QUEUE_EXPECTED_KEY) === null) {
            $this->storeTimestamp(self::BASELINE_QUEUE_EXPECTED_KEY);
        }
    }

    /** @return array<string, mixed> */
    public function baselineQueueSnapshot(): array
    {
        $heartbeat = $this->timestamp(self::BASELINE_QUEUE_HEARTBEAT_KEY);
        $expectedSince = $this->timestamp(self::BASELINE_QUEUE_EXPECTED_KEY);
        $status = $this->baselineHeartbeatStatus($heartbeat, $expectedSince);
        $result = [
            'status' => $status,
            'startup_grace' => $heartbeat === null && $status === 'warning',
            'delayed' => false,
            'last_heartbeat' => $heartbeat,
            'pending_jobs' => null,
            'processing_jobs' => null,
            'failed_jobs' => null,
            'oldest_pending_seconds' => null,
            'last_successful_preparation' => null,
            'backlog_limit' => (int) config('monitoring.spc.automatic_baseline.backlog_limit', 25),
        ];
        $connection = config('queue.connections.'.config('queue.default'));
        if (($connection['driver'] ?? null) !== 'database') {
            $result['status'] = 'unavailable';

            return $result;
        }

        try {
            $jobs = DB::connection($connection['connection'] ?? null)
                ->table($connection['table'] ?? 'jobs')
                ->where('queue', 'baseline-preparation');
            $pending = (clone $jobs)->whereNull('reserved_at')->count();
            $processing = (clone $jobs)->whereNotNull('reserved_at')->count();
            $oldest = (clone $jobs)->whereNull('reserved_at')->min('created_at');
            $failed = config('queue.failed');
            $failedJobs = ($failed['driver'] ?? null) === 'database-uuids'
                ? DB::connection($failed['database'] ?? null)
                    ->table($failed['table'] ?? 'failed_jobs')
                    ->where('queue', 'baseline-preparation')
                    ->count()
                : null;

            $result['pending_jobs'] = $pending;
            $result['processing_jobs'] = $processing;
            $result['failed_jobs'] = $failedJobs;
            $result['oldest_pending_seconds'] = $oldest === null ? null : max(0, now()->timestamp - (int) $oldest);
            $result['last_successful_preparation'] = BaselinePreparationRun::query()
                ->where('status', 'completed')
                ->where('created_drafts', '>', 0)
                ->max('completed_at');

            $warningBacklog = max(1, (int) config('monitoring.spc.automatic_baseline.backlog_warning', 20));
            $warningAge = max(1, (int) config('monitoring.spc.automatic_baseline.oldest_pending_warning_seconds', 3600));
            $result['delayed'] = (
                ($pending + $processing) >= $warningBacklog
                || ($result['oldest_pending_seconds'] !== null && $result['oldest_pending_seconds'] >= $warningAge)
                || ($failedJobs !== null && $failedJobs > 0)
            );
            if ($result['status'] === 'healthy' && $result['delayed']) {
                $result['status'] = 'warning';
            }
        } catch (QueryException) {
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    public function baselineQueueStatus(): string
    {
        return $this->baselineQueueSnapshot()['status'];
    }

    private function recordThrottledHeartbeat(string $key): void
    {
        $lastHeartbeat = $this->timestamp($key);
        $minimumInterval = (int) config('monitoring.queue.heartbeat_write_interval_seconds', 15);

        if ($lastHeartbeat !== null && $lastHeartbeat->diffInSeconds(now()) < $minimumInterval) {
            return;
        }

        $this->storeTimestamp($key);
    }

    /** @return array{status: string, last_heartbeat: ?Carbon, pending_jobs: ?int, oldest_pending_seconds: ?int} */
    public function sloQueueSnapshot(): array
    {
        $enabled = (bool) config('monitoring.slo.automatic_preparation_enabled');
        $heartbeat = $this->timestamp(self::SLO_QUEUE_HEARTBEAT_KEY);
        $result = [
            'status' => $enabled ? $this->statusFor($heartbeat, 'queue') : 'disabled',
            'last_heartbeat' => $heartbeat,
            'pending_jobs' => null,
            'oldest_pending_seconds' => null,
        ];
        $connection = config('queue.connections.'.config('queue.default'));
        if (($connection['driver'] ?? null) !== 'database') {
            $result['status'] = $enabled ? 'unavailable' : 'disabled';

            return $result;
        }

        try {
            $pending = DB::connection($connection['connection'] ?? null)
                ->table($connection['table'] ?? 'jobs')
                ->where('queue', 'slo-calibration')
                ->selectRaw('COUNT(*) as pending_jobs, MIN(created_at) as oldest_created_at')
                ->first();
            $result['pending_jobs'] = (int) $pending->pending_jobs;
            $result['oldest_pending_seconds'] = $pending->oldest_created_at === null
                ? null : max(0, now()->timestamp - (int) $pending->oldest_created_at);
        } catch (QueryException) {
            $result['status'] = $enabled ? 'unavailable' : 'disabled';
        }

        return $result;
    }

    public function recordQueueJobSucceeded(): void
    {
        $this->recordQueueHeartbeat();
        $this->storeTimestamp(self::QUEUE_LAST_SUCCESS_KEY);
    }

    public function recordQueueJobFailed(): void
    {
        $this->recordQueueHeartbeat();
        $this->storeTimestamp(self::QUEUE_LAST_FAILURE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(): array
    {
        $schedulerHeartbeat = $this->timestamp(self::SCHEDULER_HEARTBEAT_KEY);
        $queueHeartbeat = $this->timestamp(self::QUEUE_HEARTBEAT_KEY);

        return [
            'scheduler' => [
                'status' => $this->statusFor($schedulerHeartbeat, 'scheduler'),
                'last_heartbeat' => $schedulerHeartbeat,
                'expected_interval_seconds' => (int) config('monitoring.scheduler.expected_interval_seconds', 60),
            ],
            'queue' => [
                'status' => $this->statusFor($queueHeartbeat, 'queue'),
                'last_heartbeat' => $queueHeartbeat,
                'last_success' => $this->timestamp(self::QUEUE_LAST_SUCCESS_KEY),
                'last_failure' => $this->timestamp(self::QUEUE_LAST_FAILURE_KEY),
                ...$this->queueCounts(),
            ],
            'monitoring' => $this->monitoringSnapshot(),
            'application' => [
                'database_healthy' => $this->databaseIsHealthy(),
                'environment' => (string) config('app.env'),
                'version' => (string) config('app.version', 'unknown'),
                'server_time' => now(),
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }

    public function schedulerStatus(): string
    {
        return $this->statusFor($this->timestamp(self::SCHEDULER_HEARTBEAT_KEY), 'scheduler');
    }

    public function queueStatus(): string
    {
        return $this->statusFor($this->timestamp(self::QUEUE_HEARTBEAT_KEY), 'queue');
    }

    private function storeTimestamp(string $key): void
    {
        Cache::forever($key, now()->toIso8601String());
    }

    private function timestamp(string $key): ?Carbon
    {
        $value = Cache::get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function statusFor(?Carbon $heartbeat, string $component): string
    {
        if ($heartbeat === null) {
            return 'down';
        }

        $age = $heartbeat->diffInSeconds(now(), false);
        $warningAfter = (int) config("monitoring.{$component}.warning_after_seconds");
        $downAfter = (int) config("monitoring.{$component}.down_after_seconds");

        if ($age <= $warningAfter) {
            return 'healthy';
        }

        if ($age <= $downAfter) {
            return 'warning';
        }

        return 'down';
    }

    private function baselineHeartbeatStatus(?Carbon $heartbeat, ?Carbon $expectedSince): string
    {
        if ($heartbeat !== null) {
            $status = $this->statusFor($heartbeat, 'queue');

            return $status === 'down' ? 'unavailable' : $status;
        }

        $grace = max(0, (int) config('monitoring.spc.automatic_baseline.startup_grace_seconds', 180));
        if ($expectedSince !== null && $expectedSince->diffInSeconds(now(), false) <= $grace) {
            return 'warning';
        }

        return 'unavailable';
    }

    /**
     * @return array{pending_jobs: ?int, failed_jobs: ?int}
     */
    public function queueCounts(): array
    {
        $connection = config('queue.connections.'.config('queue.default'));

        if (($connection['driver'] ?? null) !== 'database') {
            return ['pending_jobs' => null, 'failed_jobs' => null];
        }

        try {
            $pending = DB::connection($connection['connection'] ?? null)
                ->table($connection['table'] ?? 'jobs')
                ->count();

            $failed = config('queue.failed');
            $failedJobs = ($failed['driver'] ?? null) === 'database-uuids'
                ? DB::connection($failed['database'] ?? null)->table($failed['table'] ?? 'failed_jobs')->count()
                : null;

            return ['pending_jobs' => $pending, 'failed_jobs' => $failedJobs];
        } catch (QueryException) {
            return ['pending_jobs' => null, 'failed_jobs' => null];
        }
    }

    /**
     * @return array{last_automatic_success: ?Carbon, last_automatic_failure: ?Carbon, active_services: int, due_services: int}
     */
    private function monitoringSnapshot(): array
    {
        $services = MonitoredService::query()
            ->where('is_active', true)
            ->with('latestServiceCheck')
            ->get(['id', 'check_interval_minutes']);

        return [
            'last_automatic_success' => ServiceCheck::query()
                ->where('source', ServiceCheck::SOURCE_AUTOMATIC)
                ->where('is_success', true)
                ->latest('checked_at')
                ->first()?->checked_at,
            'last_automatic_failure' => ServiceCheck::query()
                ->where('source', ServiceCheck::SOURCE_AUTOMATIC)
                ->where('is_success', false)
                ->latest('checked_at')
                ->first()?->checked_at,
            'active_services' => $services->count(),
            'due_services' => $services->filter(function (MonitoredService $service): bool {
                $latestCheck = $service->latestServiceCheck;

                return $latestCheck === null || $latestCheck->checked_at->lte(
                    now()->subMinutes((int) $service->check_interval_minutes),
                );
            })->count(),
        ];
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
