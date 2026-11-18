<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaselinePreparationRun extends Model
{
    protected $fillable = [
        'monitored_service_id',
        'policy_version',
        'cutoff_at',
        'analysis_timezone',
        'status',
        'attempts',
        'created_drafts',
        'dispatched_at',
        'started_at',
        'completed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
            'created_drafts' => 'integer',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
