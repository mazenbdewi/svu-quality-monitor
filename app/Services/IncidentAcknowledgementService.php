<?php

namespace App\Services;

use App\Models\ServiceIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class IncidentAcknowledgementService
{
    public function acknowledge(User $actor, ServiceIncident $incident): ServiceIncident
    {
        if (! $actor->can('incidents.acknowledge')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $incident): ServiceIncident {
            $locked = ServiceIncident::query()->lockForUpdate()->findOrFail($incident->id);
            if ($locked->acknowledged_at !== null) {
                return $locked;
            }
            $locked->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
            app(AuditLogger::class)->log('incident.acknowledged', $locked, __('administration.audit.events')['incident.acknowledged'], context: ['service_id' => $locked->monitored_service_id], actor: $actor);

            return $locked;
        });
    }
}
