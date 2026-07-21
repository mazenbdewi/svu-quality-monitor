<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Illuminate\Database\Eloquent\Collection;

class IncidentDetector
{
    public function evaluate(MonitoredService $service): ?ServiceIncident
    {
        $latestChecks = $service->serviceChecks()
            ->latest('checked_at')
            ->limit(3)
            ->get();

        $openIncident = $service->openIncident()->first();

        if ($openIncident === null) {
            return $this->openIncidentIfNeeded($service, $latestChecks);
        }

        return $this->closeIncidentIfRecovered($openIncident, $latestChecks);
    }

    /**
     * @param  Collection<int, ServiceCheck>  $latestChecks
     */
    private function openIncidentIfNeeded(MonitoredService $service, Collection $latestChecks): ?ServiceIncident
    {
        if ($latestChecks->count() < 3) {
            return null;
        }

        if ($latestChecks->contains(fn (ServiceCheck $check): bool => ! $this->isProblematic($check))) {
            return null;
        }

        $incidentType = $this->detectIncidentType($latestChecks);

        return $service->serviceIncidents()->create([
            'started_at' => $latestChecks->last()->checked_at,
            'incident_type' => $incidentType,
            'severity' => $this->detectSeverity($incidentType),
            'status' => 'open',
        ]);
    }

    /**
     * @param  Collection<int, ServiceCheck>  $latestChecks
     */
    private function closeIncidentIfRecovered(ServiceIncident $incident, Collection $latestChecks): ?ServiceIncident
    {
        if ($latestChecks->count() < 2) {
            return $incident;
        }

        $latestTwoChecks = $latestChecks->take(2);

        if ($latestTwoChecks->contains(fn (ServiceCheck $check): bool => ! $this->isHealthy($check))) {
            return $incident;
        }

        $endedAt = $latestTwoChecks->first()->checked_at ?? now();

        $incident->update([
            'status' => 'closed',
            'ended_at' => $endedAt,
            'duration_minutes' => (int) $incident->started_at->diffInMinutes($endedAt),
        ]);

        return $incident->refresh();
    }

    private function isProblematic(ServiceCheck $check): bool
    {
        return (! $check->is_success) || $check->is_slow;
    }

    private function isHealthy(ServiceCheck $check): bool
    {
        return $check->is_success && (! $check->is_slow);
    }

    /**
     * @param  Collection<int, ServiceCheck>  $checks
     */
    private function detectIncidentType(Collection $checks): string
    {
        $types = $checks
            ->map(fn (ServiceCheck $check): string => $this->problemType($check))
            ->unique()
            ->values();

        if ($types->count() > 1) {
            return 'mixed';
        }

        return $types->first() ?? 'unknown';
    }

    private function problemType(ServiceCheck $check): string
    {
        if ($check->is_slow && $check->is_success) {
            return 'slow';
        }

        if (in_array($check->error_type, ['timeout', 'connection_error', 'keyword_missing'], true)) {
            return $check->error_type;
        }

        if ($check->status_code !== null && $check->status_code >= 500) {
            return 'server_error';
        }

        if (! $check->is_success) {
            return 'down';
        }

        if ($check->is_slow) {
            return 'slow';
        }

        return 'unknown';
    }

    private function detectSeverity(string $incidentType): string
    {
        return match ($incidentType) {
            'down', 'timeout', 'connection_error' => 'critical',
            'server_error' => 'high',
            'slow', 'keyword_missing', 'mixed' => 'medium',
            default => 'low',
        };
    }
}
