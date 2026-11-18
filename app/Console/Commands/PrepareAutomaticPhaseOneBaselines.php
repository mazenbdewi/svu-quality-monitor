<?php

namespace App\Console\Commands;

use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use Illuminate\Console\Command;

class PrepareAutomaticPhaseOneBaselines extends Command
{
    protected $signature = 'baselines:prepare-due';

    protected $description = 'Dispatch deterministic Phase I draft preparation for incomplete automatic baseline sets.';

    public function handle(AutomaticPhaseOneBaselineService $preparation): int
    {
        $counts = $preparation->dispatchDue();
        $this->line(implode('; ', [
            "eligible: {$counts['eligible']}",
            "dispatched: {$counts['dispatched']}",
            "duplicate: {$counts['duplicate']}",
            "skipped: {$counts['skipped']}",
            "deferred: {$counts['deferred']}",
            "failed: {$counts['failed']}",
        ]).'.');
        if ($counts['deferred'] > 0) {
            $this->warn('Baseline preparation is delayed by the per-run or backlog limit; deferred services remain eligible for a future fair dispatch.');
        }
        if ($counts['failed'] > 0) {
            $this->error('One or more baseline preparation jobs could not be enqueued.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
