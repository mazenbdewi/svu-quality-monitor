<?php

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MaintenanceWindowService
{
    public function activeWindowFor(MonitoredService $service, Carbon $at): ?MaintenanceWindow
    {
        return $this->applicableTo($service)
            ->activeAt($this->utc($at))
            ->orderBy('starts_at')
            ->first();
    }

    public function isUnderMaintenance(MonitoredService $service, ?Carbon $at = null): bool
    {
        return $this->activeWindowFor($service, $at ?? now()) !== null;
    }

    /** @return Collection<int, array{start: Carbon, end: Carbon}> */
    public function mergedOverlapIntervals(MonitoredService $service, Carbon $from, Carbon $to): Collection
    {
        $from = $this->utc($from);
        $to = $this->utc($to);
        if ($to->lte($from)) {
            return collect();
        }

        $intervals = $this->applicableTo($service)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (MaintenanceWindow $window): array => [
                'start' => $window->starts_at->greaterThan($from) ? $window->starts_at->copy() : $from->copy(),
                'end' => $window->ends_at->lessThan($to) ? $window->ends_at->copy() : $to->copy(),
            ]);

        $merged = collect();
        foreach ($intervals as $interval) {
            $lastIndex = $merged->count() - 1;
            $last = $lastIndex >= 0 ? $merged->get($lastIndex) : null;
            if ($last !== null && $interval['start']->lte($last['end'])) {
                $last['end'] = $interval['end']->greaterThan($last['end']) ? $interval['end'] : $last['end'];
                $merged->put($lastIndex, $last);

                continue;
            }
            $merged->push($interval);
        }

        return $merged;
    }

    public function overlapMinutes(MonitoredService $service, Carbon $from, Carbon $to): int
    {
        return $this->mergedOverlapIntervals($service, $from, $to)
            ->sum(fn (array $interval): int => $this->minutesBetween($interval['start'], $interval['end']));
    }

    /** @param list<int|string> $serviceIds */
    public function assertValidWindow(Carbon $startsAt, Carbon $endsAt, bool $allServices, array $serviceIds, ?int $ignoreWindowId = null): void
    {
        $startsAt = $this->utc($startsAt);
        $endsAt = $this->utc($endsAt);
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        $errors = [];
        if ($endsAt->lte($startsAt)) {
            $errors['ends_at'] = __('monitoring.maintenance.validation.ends_after_starts');
        }
        if (! $allServices && $serviceIds === []) {
            $errors['monitored_services'] = __('monitoring.maintenance.validation.service_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $conflict = MaintenanceWindow::query()
            ->when($ignoreWindowId, fn (Builder $query, int $id): Builder => $query->whereKeyNot($id))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function (Builder $query) use ($allServices, $serviceIds): void {
                if ($allServices) {
                    $query->where('applies_to_all_services', true)->orWhereHas('monitoredServices');

                    return;
                }
                $query->where('applies_to_all_services', true)
                    ->orWhereHas('monitoredServices', fn (Builder $services) => $services->whereIn('monitored_services.id', $serviceIds));
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => __('monitoring.maintenance.validation.overlap'),
            ]);
        }
    }

    private function applicableTo(MonitoredService $service): Builder
    {
        return MaintenanceWindow::query()->where(function (Builder $query) use ($service): void {
            $query->where('applies_to_all_services', true)
                ->orWhereHas('monitoredServices', fn (Builder $services) => $services->whereKey($service->id));
        });
    }

    private function utc(Carbon $at): Carbon
    {
        return $at->copy()->utc();
    }

    private function minutesBetween(Carbon $from, Carbon $to): int
    {
        return max(0, (int) ceil($from->diffInSeconds($to, false) / 60));
    }
}
