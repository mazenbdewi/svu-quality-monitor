<?php

namespace App\Jobs;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Monitoring\MeasurementLimits;
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
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckMonitoredServiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = MeasurementLimits::JOB_TIMEOUT_SECONDS;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $monitoredServiceId) {}

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

        $service = MonitoredService::query()->find($this->monitoredServiceId);

        if ($service === null || ! $service->is_active) {
            return;
        }

        $runner->run($service, ServiceCheck::SOURCE_AUTOMATIC);
    }

    public function failed(Throwable $exception): void
    {
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
