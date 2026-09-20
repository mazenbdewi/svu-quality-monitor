<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class SpcAnalysisWindow
{
    public function timezone(): string
    {
        return config('monitoring.spc.analysis_timezone', 'Asia/Damascus');
    }

    /** Calendar windows in analysis timezone, returned as UTC half-open instants. */
    public function resolve(string $window = '30', ?string $from = null, ?string $to = null): array
    {
        if ($window === 'custom') {
            if (! $from || ! $to) {
                throw new InvalidArgumentException('Custom analysis requires start and exclusive end.');
            }
            $start = Carbon::parse($from, $this->timezone());
            $end = Carbon::parse($to, $this->timezone());
        } else {
            if (! in_array($window, ['7', '30', '90'], true)) {
                throw new InvalidArgumentException('Analysis window must be 7, 30, 90 or custom.');
            }
            $end = $to ? Carbon::parse($to, $this->timezone()) : now($this->timezone())->startOfDay();
            $start = $end->copy()->subDays((int) $window);
        }
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Analysis end must follow start.');
        }

        return [$start->utc(), $end->utc()];
    }

    public function exportBounds(array $filters): array
    {
        if (isset($filters['analysis_start'], $filters['analysis_end'])) {
            return $this->resolve('custom', $filters['analysis_start'], $filters['analysis_end']);
        }
        $end = isset($filters['date_to']) ? Carbon::parse($filters['date_to'], $this->timezone())->startOfDay()->addDay() : now($this->timezone())->startOfDay();
        $start = isset($filters['date_from']) ? Carbon::parse($filters['date_from'], $this->timezone())->startOfDay() : $end->copy()->subDays(30);

        return [$start->utc(), $end->utc()];
    }
}
