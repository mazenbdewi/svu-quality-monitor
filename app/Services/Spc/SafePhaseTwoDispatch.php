<?php

namespace App\Services\Spc;

use App\Jobs\EvaluateSpcPhaseTwo;
use App\Models\ControlChartBaseline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafePhaseTwoDispatch
{
    public function dispatch(int $serviceId): void
    {
        try {
            if (ControlChartBaseline::where('monitored_service_id', $serviceId)->where('status', 'approved')->exists()) {
                Bus::dispatch(new EvaluateSpcPhaseTwo($serviceId));
            }
        } catch (Throwable $error) {
            Log::warning('SPC evaluation dispatch failed; operational monitoring was already committed.', ['service_id' => $serviceId, 'exception_class' => $error::class]);
        }
    }
}
