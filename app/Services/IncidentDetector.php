<?php

namespace App\Services;

use App\Events\IncidentConfirmed;
use App\Events\IncidentResolved;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Illuminate\Support\Facades\DB;

class IncidentDetector
{
    public function evaluate(MonitoredService $service, ?ServiceCheck $check = null): ?ServiceIncident
    {
        $check ??= $service->serviceChecks()->latest('checked_at')->first();
        if ($check === null) {
            return null;
        }

        return DB::transaction(function () use ($service, $check): ?ServiceIncident {
            $lockedService = MonitoredService::query()->lockForUpdate()->findOrFail($service->id);
            $openIncident = $lockedService->openIncident()->lockForUpdate()->first();
            if ((int) $lockedService->last_incident_processed_check_id === $check->id) {
                return $openIncident;
            }

            if ($check->is_during_maintenance && $openIncident === null) {
                $this->clearMaintenanceOnlyConfirmationState($lockedService);
                $lockedService->last_incident_processed_check_id = $check->id;
                $lockedService->save();

                return null;
            }

            $incident = $check->is_success
                ? $this->handleSuccess($lockedService, $openIncident, $check)
                : $this->handleFailure($lockedService, $openIncident, $check);

            $lockedService->last_incident_processed_check_id = $check->id;
            $lockedService->save();

            return $incident;
        });
    }

    private function clearMaintenanceOnlyConfirmationState(MonitoredService $service): void
    {
        if ($service->operational_state === 'pending_failure') {
            $this->transition($service, 'healthy');
        }
        $service->consecutive_failures = 0;
        $service->consecutive_recovery_successes = 0;
        $service->first_failure_at = null;
        $service->recovery_started_at = null;
    }

    private function handleFailure(MonitoredService $service, ?ServiceIncident $incident, ServiceCheck $check): ?ServiceIncident
    {
        if ($incident !== null) {
            if ($service->operational_state === 'recovering') {
                $this->transition($service, 'down');
            }
            $service->consecutive_recovery_successes = 0;
            $service->recovery_started_at = null;
            $service->consecutive_failures++;
            $incident->update(['failure_count' => $service->consecutive_failures, 'latest_failure_type' => $check->error_type, 'latest_failure_message' => $check->error_message]);

            return $incident->refresh();
        }

        if ($service->operational_state !== 'pending_failure') {
            $this->transition($service, 'pending_failure');
            $service->consecutive_failures = 0;
            $service->first_failure_at = $check->checked_at;
        }
        $service->consecutive_failures++;
        if ($service->consecutive_failures < max(1, (int) $service->failure_confirmation_count)) {
            return null;
        }

        $this->transition($service, 'down');

        $created = $service->serviceIncidents()->create([
            'started_at' => $service->first_failure_at ?? $check->checked_at,
            'confirmed_at' => $check->checked_at,
            'incident_type' => $check->error_type ?: 'down',
            'severity' => in_array($check->error_type, ['timeout', 'connection_error', 'connection_refused', 'dns_failure', 'tls_failure'], true) ? 'critical' : 'high',
            'status' => 'open', 'failure_count' => $service->consecutive_failures,
            'initial_failure_type' => $check->error_type, 'initial_failure_message' => $check->error_message,
            'latest_failure_type' => $check->error_type, 'latest_failure_message' => $check->error_message,
        ]);
        event(new IncidentConfirmed($created->id));

        return $created;
    }

    private function handleSuccess(MonitoredService $service, ?ServiceIncident $incident, ServiceCheck $check): ?ServiceIncident
    {
        if ($incident === null) {
            if ($service->operational_state === 'pending_failure') {
                $this->transition($service, 'healthy');
            }
            $service->consecutive_failures = 0;
            $service->first_failure_at = null;

            return null;
        }

        if ($service->operational_state !== 'recovering') {
            $this->transition($service, 'recovering');
            $service->consecutive_recovery_successes = 0;
            $service->recovery_started_at = $check->checked_at;
        }
        $service->consecutive_recovery_successes++;
        if ($service->consecutive_recovery_successes < max(1, (int) $service->recovery_confirmation_count)) {
            return $incident;
        }

        $resolvedAt = $service->recovery_started_at ?? $check->checked_at;
        $incident->update(['status' => 'closed', 'ended_at' => $resolvedAt, 'resolved_at' => $resolvedAt, 'duration_minutes' => (int) $incident->started_at->diffInMinutes($resolvedAt)]);
        event(new IncidentResolved($incident->id));
        $this->transition($service, 'healthy');
        $service->consecutive_failures = 0;
        $service->consecutive_recovery_successes = 0;
        $service->first_failure_at = null;
        $service->recovery_started_at = null;

        return $incident->refresh();
    }

    private function transition(MonitoredService $service, string $state): void
    {
        if ($service->operational_state === $state) {
            return;
        }
        $now = now();
        $window = (int) config('monitoring.incidents.flapping_window_seconds', 900);
        if ($service->state_transition_window_started_at === null || $service->state_transition_window_started_at->diffInSeconds($now) > $window) {
            $service->state_transition_window_started_at = $now;
            $service->state_transition_count = 0;
            $service->is_flapping = false;
        }
        $service->state_transition_count++;
        $service->is_flapping = $service->state_transition_count >= (int) config('monitoring.incidents.flapping_transition_count', 4);
        $service->operational_state = $state;
    }
}
