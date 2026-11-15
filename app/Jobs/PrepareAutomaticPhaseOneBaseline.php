<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareAutomaticPhaseOneBaseline implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $serviceId,
        public readonly int $actorId,
        public readonly string $cutoff,
        public readonly string $timezone,
        public readonly string $policyVersion,
    ) {}

    public function uniqueId(): string
    {
        return implode('|', [$this->serviceId, $this->policyVersion, $this->cutoff, $this->timezone]);
    }

    public function handle(AutomaticPhaseOneBaselineService $service): void
    {
        $actor = User::query()->find($this->actorId);
        if ($actor) {
            $service->prepareOne($this->serviceId, $actor, $this->cutoff, $this->timezone, $this->policyVersion);
        }
    }
}
