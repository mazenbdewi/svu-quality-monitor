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
        'ended_at',
        'duration_minutes',
        'incident_type',
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
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
