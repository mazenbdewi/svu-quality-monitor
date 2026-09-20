<?php

namespace App\Console\Commands;

use App\Models\ControlChartBaseline;
use App\Services\Spc\SafePhaseTwoDispatch;
use Illuminate\Console\Command;

class EvaluateSpcPhaseTwo extends Command
{
    protected $signature = 'spc:evaluate';

    protected $description = 'Queue live Phase II evaluations and close elapsed P subgroups.';

    public function handle(SafePhaseTwoDispatch $dispatch): int
    {
        foreach (ControlChartBaseline::where('status', 'approved')->distinct()->pluck('monitored_service_id') as $id) {
            $dispatch->dispatch($id);
        }

        return self::SUCCESS;
    }
}
