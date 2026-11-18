<?php

namespace App\Jobs;

use App\Models\BaselinePreparationRun;
use App\Models\User;
use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PrepareAutomaticPhaseOneBaseline implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $serviceId,
        public readonly ?int $actorId,
        public readonly string $cutoff,
        public readonly string $timezone,
        public readonly string $policyVersion,
        public readonly ?int $preparationRunId = null,
    ) {
        $this->onQueue('baseline-preparation');
    }

    public function uniqueId(): string
    {
        return implode('|', [$this->serviceId, $this->policyVersion, $this->cutoff, $this->timezone]);
    }

    public function handle(AutomaticPhaseOneBaselineService $service): void
    {
        // Pre-upgrade serialized jobs have no promoted run-ID property.
        $runId = $this->preparationRunId ?? null;
        $run = $runId === null ? null : BaselinePreparationRun::query()->find($runId);
        if ($run?->status === 'completed') {
            return;
        }
        $run?->update([
            'status' => 'processing',
            'started_at' => $run->started_at ?? now(),
            'attempts' => $run->attempts + 1,
            'last_error' => null,
        ]);

        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        if ($this->actorId !== null && ! $actor) {
            $run?->update(['status' => 'completed', 'completed_at' => now()]);

            return;
        }

        $result = $service->prepareOne($this->serviceId, $actor, $this->cutoff, $this->timezone, $this->policyVersion);
        $run?->update([
            'status' => 'completed',
            'created_drafts' => count($result['created']),
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if (($this->preparationRunId ?? null) === null) {
            return;
        }

        BaselinePreparationRun::query()->whereKey($this->preparationRunId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'last_error' => $exception === null
                ? 'Queue job failed without an exception.'
                : 'Preparation failed ('.class_basename($exception).').',
        ]);
    }
}
