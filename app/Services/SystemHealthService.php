<?php

namespace App\Services;

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

    public function recordSchedulerHeartbeat(): void
    {
        $this->storeTimestamp(self::SCHEDULER_HEARTBEAT_KEY);
    }

    public function recordQueueHeartbeat(): void
    {
        $lastHeartbeat = $this->timestamp(self::QUEUE_HEARTBEAT_KEY);
        $minimumInterval = (int) config('monitoring.queue.heartbeat_write_interval_seconds', 15);

        if ($lastHeartbeat !== null && $lastHeartbeat->diffInSeconds(now()) < $minimumInterval) {
            return;
        }

        $this->storeTimestamp(self::QUEUE_HEARTBEAT_KEY);
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
