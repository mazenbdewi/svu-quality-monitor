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
        $baseline = $this->health->baselineQueueSnapshot();
        $statuses = [
            'scheduler' => ['status' => $this->health->schedulerStatus(), 'alertable' => true],
            'queue' => ['status' => $this->health->queueStatus(), 'alertable' => true],
            // A missing heartbeat during the explicit startup grace period
            // is visible as warning but must not open or close an alert cycle.
            'baseline_preparation' => [
                'status' => $baseline['status'],
                'alertable' => ! $baseline['startup_grace'] || $baseline['delayed'],
            ],
        ];
        foreach ($statuses as $component => $health) {
            if (! $health['alertable']) {
                continue;
            }
            $status = $health['status'];
            DB::transaction(function () use ($component, $status): void {
                $state = SystemHealthAlertState::query()->lockForUpdate()->firstOrCreate(['component' => $component], ['status' => 'healthy']);
                $isProblem = $component === 'baseline_preparation'
                    ? in_array($status, ['warning', 'unavailable'], true)
                    : $status === 'down';
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
