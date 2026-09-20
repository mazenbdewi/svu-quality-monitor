<?php

namespace App\Services\Spc;

use App\Models\MonitoredService;
use App\Services\MaintenanceWindowService;
use App\Services\ReliabilityTimeIntervals;
use App\Services\SpcResearchData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PhaseTwoPopulation
{
    public function __construct(private SpcResearchData $research, private MaintenanceWindowService $maintenance, private ReliabilityTimeIntervals $intervals) {}

    public function checks(MonitoredService $service, Carbon $start, Carbon $end, Carbon $cutoff, bool $latency = false): Collection
    {
        return $this->research->checks($service, $start, $end, $latency)->filter(fn ($c) => $c->checked_at->lte($cutoff) && $c->created_at !== null && $c->created_at->lte($cutoff) && ($c->updated_at === null || $c->updated_at->lte($cutoff)))->values();
    }

    public function bucket(MonitoredService $service, Carbon $start, Carbon $end, Carbon $cutoff): array
    {
        $checks = $this->checks($service, $start, $end, $cutoff);
        $segments = $this->intervals->subtract(collect([['start' => $start, 'end' => $end]]), $this->maintenance->mergedOverlapIntervals($service, $start, $end));
        $interval = max(1, (int) $service->check_interval_minutes) * 60;
        $expected = (int) ceil($this->intervals->seconds($segments) / $interval);
        $slots = [];
        foreach ($checks as $c) {
            $offset = 0;
            foreach ($segments as $s) {
                if ($c->checked_at->gte($s['start']) && $c->checked_at->lt($s['end'])) {
                    $slots[(int) floor(($offset + $s['start']->diffInSeconds($c->checked_at)) / $interval)] = true;
                    break;
                }
                $offset += $s['start']->diffInSeconds($s['end']);
            }
        }
        $n = $checks->count();
        $d = $checks->filter(fn ($c) => $c->is_problematic)->count();

        return ['bucket_start' => $start->toIso8601String(), 'bucket_end' => $end->toIso8601String(), 'n' => $n, 'd' => $d,
            'expected' => $expected, 'covered' => count($slots), 'coverage' => $expected ? 100 * count($slots) / $expected : null,
            'status' => $n === 0 || $expected === 0 ? 'missing' : (count($slots) < $expected ? 'insufficient_coverage' : 'eligible'),
            'sources' => $checks->map(fn ($c) => ['check_id' => $c->id, 'checked_at' => $c->checked_at->toIso8601String(), 'available_at' => $c->created_at->toIso8601String(), 'problematic' => (bool) $c->is_problematic])->all()];
    }
}
