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
        $this->line("Eligible: {$counts['eligible']}; dispatched: {$counts['dispatched']}.");

        return self::SUCCESS;
    }
}
