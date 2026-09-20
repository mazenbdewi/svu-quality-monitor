<?php

namespace App\Services;

use Illuminate\Support\Collection;

/** Half-open time intervals. All calculations retain seconds until presentation. */
final class ReliabilityTimeIntervals
{
    public function union(Collection $intervals): Collection
    {
        $merged = collect();
        foreach ($intervals->filter(fn ($i) => $i['end']->gt($i['start']))->sortBy('start') as $interval) {
            $last = $merged->last();
            if ($last && $interval['start']->lte($last['end'])) {
                $last['end'] = $last['end']->copy()->max($interval['end']);
                $merged->put($merged->count() - 1, $last);
            } else {
                $merged->push($interval);
            }
        }

        return $merged;
    }

    public function subtract(Collection $intervals, Collection $exclusions): Collection
    {
        $remaining = $this->union($intervals);
        foreach ($this->union($exclusions) as $excluded) {
            $remaining = $remaining->flatMap(function ($interval) use ($excluded) {
                if ($excluded['end']->lte($interval['start']) || $excluded['start']->gte($interval['end'])) {
                    return [$interval];
                }

                return array_values(array_filter([
                    $excluded['start']->gt($interval['start']) ? ['start' => $interval['start'], 'end' => $excluded['start']] : null,
                    $excluded['end']->lt($interval['end']) ? ['start' => $excluded['end'], 'end' => $interval['end']] : null,
                ]));
            });
        }

        return $remaining;
    }

    public function seconds(Collection $intervals): int
    {
        return (int) $intervals->sum(fn ($i) => $i['start']->diffInSeconds($i['end']));
    }
}
