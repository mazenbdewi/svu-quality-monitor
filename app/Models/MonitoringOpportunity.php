<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringOpportunity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nominal_due_at_utc' => 'datetime',
            'dispatched_at_utc' => 'datetime',
            'started_at_utc' => 'datetime',
            'completed_at_utc' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class, 'monitored_service_id');
    }

    public function epoch(): BelongsTo
    {
        return $this->belongsTo(MonitoringScheduleEpoch::class, 'monitoring_schedule_epoch_id');
    }

    public function serviceCheck(): BelongsTo
    {
        return $this->belongsTo(ServiceCheck::class);
    }
}
