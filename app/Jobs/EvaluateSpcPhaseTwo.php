<?php

namespace App\Jobs;

use App\Models\MonitoredService;
use App\Services\Spc\PhaseTwoEvaluator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateSpcPhaseTwo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public string $enqueuedAt;

    public function __construct(public int $serviceId)
    {
        $this->enqueuedAt = now()->utc()->toIso8601String();
    }

    public function handle(PhaseTwoEvaluator $evaluator): void
    {
        $startedAt = now()->utc();
        $service = MonitoredService::find($this->serviceId);
        if ($service && $service->is_active) {
            $evaluator->evaluate($service, executionContext: [
                'job_enqueued_at' => $this->enqueuedAt ?? null,
                'job_started_at' => $startedAt->toIso8601String(),
                'queue_delay_seconds' => isset($this->enqueuedAt) ? max(0, Carbon::parse($this->enqueuedAt)->diffInSeconds($startedAt)) : null,
            ]);
        }
    }
}
