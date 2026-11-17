<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringScheduleEpoch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'configuration_snapshot' => 'array',
            'effective_from_utc' => 'datetime',
            'effective_until_utc' => 'datetime',
            'next_nominal_due_at_utc' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class, 'monitored_service_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(MonitoringOpportunity::class);
    }
}
