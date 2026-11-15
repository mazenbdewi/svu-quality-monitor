<?php

namespace App\Jobs;

use App\Services\SloAutomaticPreparation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareAutomaticSloProposal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 7200;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $serviceId,
        public readonly string $cutoff,
        public readonly string $timezone,
        public readonly string $policyVersion,
        public readonly string $configurationFingerprint,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serviceId;
    }

    public function handle(SloAutomaticPreparation $preparation): void
    {
        $preparation->prepareOne($this->serviceId, $this->cutoff, $this->timezone, $this->policyVersion, $this->configurationFingerprint);
        Cache::forget(SloAutomaticPreparation::statusKey($this->serviceId));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Automatic SLO proposal job failed.', [
            'monitored_service_id' => $this->serviceId, 'exception_class' => $exception::class,
        ]);
        try {
            Cache::forget(SloAutomaticPreparation::statusKey($this->serviceId));
        } catch (Throwable $cleanupException) {
            Log::warning('Automatic SLO proposal status cleanup failed.', [
                'monitored_service_id' => $this->serviceId, 'exception_class' => $cleanupException::class,
            ]);
        }
    }
}
