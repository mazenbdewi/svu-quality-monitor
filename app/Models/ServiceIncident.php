<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceIncident extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'monitored_service_id',
        'started_at',
        'confirmed_at',
        'ended_at',
        'resolved_at',
        'duration_minutes',
        'failure_count',
        'incident_type',
        'initial_failure_type',
        'initial_failure_message',
        'latest_failure_type',
        'latest_failure_message',
        'severity',
        'status',
        'root_cause',
        'corrective_action',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'ended_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
