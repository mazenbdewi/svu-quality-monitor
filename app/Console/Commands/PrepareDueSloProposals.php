<?php

namespace App\Console\Commands;

use App\Services\SloAutomaticPreparation;
use Illuminate\Console\Command;

class PrepareDueSloProposals extends Command
{
    protected $signature = 'slo:prepare-due';

    protected $description = 'Dispatch bounded, one-time SLO research calibration for eligible services.';

    public function handle(SloAutomaticPreparation $preparation): int
    {
        $counts = $preparation->dispatchDue();
        $this->line("Eligible: {$counts['eligible']}; dispatched: {$counts['dispatched']}; failed: {$counts['failed']}.");

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
