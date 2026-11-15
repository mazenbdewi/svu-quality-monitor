<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Shared read-only HTTP/API research cohort for latency and SLI analysis. */
class EligibleSyntheticChecks
{
    public function __construct(private MaintenanceWindowService $maintenance) {}

    /** @return Collection<int, array{start: Carbon, end: Carbon}> */
    public function maintenance(MonitoredService $service, Carbon $start, Carbon $end): Collection
    {
        return $this->maintenance->mergedOverlapIntervals($service, $start, $end);
    }

    public function query(MonitoredService $service, Carbon $start, Carbon $end, Collection $maintenance): Builder
    {
        $query = ServiceCheck::query()->where('monitored_service_id', $service->id)->researchChecks()
            ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)
            ->whereNotNull('created_at')->where('created_at', '<=', $end)
            ->where(fn (Builder $query) => $query->whereNull('updated_at')->orWhere('updated_at', '<=', $end));

        foreach ($maintenance as $interval) {
            $query->where(fn (Builder $query) => $query->where('checked_at', '<', $interval['start'])
                ->orWhere('checked_at', '>=', $interval['end']));
        }

        return $query;
    }
}
