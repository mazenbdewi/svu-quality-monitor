<?php

namespace App\Services;

use App\Events\SystemHealthProblemDetected;
use App\Events\SystemHealthRecovered;
use App\Models\SystemHealthAlertState;
use Illuminate\Support\Facades\DB;

class BackgroundHealthAlertService
{
    public function __construct(private SystemHealthService $health) {}

    public function check(): void
    {
        foreach (['scheduler' => $this->health->schedulerStatus(), 'queue' => $this->health->queueStatus()] as $component => $status) {
            DB::transaction(function () use ($component, $status): void {
                $state = SystemHealthAlertState::query()->lockForUpdate()->firstOrCreate(['component' => $component], ['status' => 'healthy']);
                $isProblem = $status === 'down';
                if ($isProblem && $state->status !== 'down') {
                    $state->update(['status' => 'down', 'opened_at' => now(), 'resolved_at' => null]);
                    event(new SystemHealthProblemDetected($component, $state->opened_at->toIso8601String()));
                }
                if (! $isProblem && $state->status === 'down') {
                    $cycle = $state->opened_at?->toIso8601String() ?? now()->toIso8601String();
                    $state->update(['status' => 'healthy', 'resolved_at' => now()]);
                    event(new SystemHealthRecovered($component, $cycle));
                }
            });
        }
    }
}
