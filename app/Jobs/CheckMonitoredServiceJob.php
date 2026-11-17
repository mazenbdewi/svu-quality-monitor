<?php

namespace App\Jobs;

use App\Models\MonitoredService;
use App\Models\MonitoringOpportunity;
use App\Models\ServiceCheck;
use App\Monitoring\MeasurementLimits;
use App\Services\FixedMonitoringCadence;
use App\Services\ServiceCheckRunner;
use App\Services\SystemHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CheckMonitoredServiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = MeasurementLimits::JOB_TIMEOUT_SECONDS;

    public int $timeout = MeasurementLimits::JOB_TIMEOUT_SECONDS;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public bool $failOnTimeout = true;

    private ?string $claimToken = null;

    public function __construct(public readonly int $monitoredServiceId, public readonly ?int $opportunityId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->monitoredServiceId;
    }

    public function uniqueVia(): CacheRepository
    {
        return Cache::store();
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ServiceCheckRunner $runner, SystemHealthService $health): void
    {
        $health->recordQueueHeartbeat();

        if ($this->opportunityId !== null) {
            $this->handleOpportunity($runner);

            return;
        }

        $service = MonitoredService::query()->find($this->monitoredServiceId);

        if ($service === null || ! $service->is_active) {
            return;
        }

        $runner->run($service, ServiceCheck::SOURCE_AUTOMATIC);
    }

    private function handleOpportunity(ServiceCheckRunner $runner): void
    {
        $token = (string) Str::uuid();
        $service = DB::transaction(function () use ($token): ?MonitoredService {
            $opportunity = MonitoringOpportunity::query()->lockForUpdate()->find($this->opportunityId);
            if (! $opportunity || ! in_array($opportunity->status, ['queued', 'running'], true)) {
                return null;
            }
            $service = MonitoredService::query()->find($this->monitoredServiceId);
            $epoch = $opportunity->epoch;
            if (! $service || $opportunity->monitored_service_id !== $service->id || ! $service->is_active
                || ! config('monitoring.fixed_cadence.enabled') || ! app(FixedMonitoringCadence::class)->active()
                || $epoch->effective_until_utc !== null || $epoch->configuration_fingerprint !== app(FixedMonitoringCadence::class)->fingerprint($service)) {
                $opportunity->update(['status' => 'cancelled', 'reason' => 'policy_or_configuration_changed', 'completed_at_utc' => now()->utc()]);

                return null;
            }
            if ($opportunity->status === 'running' && $opportunity->started_at_utc->gt(now()->subSeconds($this->timeout + 60))) {
                $this->release(30);

                return null;
            }
            $opportunity->update([
                'status' => 'running',
                'started_at_utc' => now()->utc(),
                'attempts' => $opportunity->attempts + 1,
                'claim_token' => $token,
            ]);
            $this->claimToken = $token;

            return $service;
        });
        if (! $service) {
            return;
        }
        try {
            $runner->run($service, ServiceCheck::SOURCE_AUTOMATIC, $this->opportunityId, $token);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($token): void {
                $opportunity = MonitoringOpportunity::query()->lockForUpdate()->find($this->opportunityId);
                if ($opportunity && $opportunity->status === 'running' && $opportunity->claim_token === $token) {
                    $closed = $opportunity->epoch->effective_until_utc !== null || ! config('monitoring.fixed_cadence.enabled');
                    $opportunity->update([
                        'status' => $closed ? 'cancelled' : 'queued',
                        'reason' => $closed ? 'policy_or_configuration_changed' : 'retry_after_error',
                        'completed_at_utc' => $closed ? now()->utc() : null,
                    ]);
                }
            });
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->opportunityId !== null) {
            $opportunity = MonitoringOpportunity::query()->whereKey($this->opportunityId);
            if ($this->claimToken !== null) {
                $opportunity->whereIn('status', ['queued', 'running'])->where('claim_token', $this->claimToken);
            } else {
                // A failure before handle() may finalize an unclaimed queued job,
                // but must never overwrite a claim recovered by another worker.
                $opportunity->where('status', 'queued');
            }
            $opportunity->update(['status' => 'failed', 'reason' => 'queue_job_failed', 'completed_at_utc' => now()->utc()]);
        }
        app(SystemHealthService::class)->recordQueueJobFailed();

        Log::error('Automatic service-check job failed.', [
            'monitored_service_id' => $this->monitoredServiceId,
            'exception_class' => $exception::class,
        ]);
    }

    private function lockKey(): string
    {
        return "monitoring:service-check:{$this->monitoredServiceId}";
    }
}
