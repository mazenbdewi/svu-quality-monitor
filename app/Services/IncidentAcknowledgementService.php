<?php

namespace App\Services;

use App\Models\ServiceIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class IncidentAcknowledgementService
{
    public function acknowledge(User $actor, ServiceIncident $incident): ServiceIncident
    {
        if (! $actor->can('incidents.acknowledge')) {
            throw new AuthorizationException;
        }
        if ($incident->acknowledged_at !== null) {
            return $incident;
        }
        $incident->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
        app(AuditLogger::class)->log('incident.acknowledged', $incident, 'Incident acknowledged');

        return $incident->fresh();
    }
}
