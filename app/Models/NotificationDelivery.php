<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = ['event_type', 'channel', 'monitored_service_id', 'service_incident_id', 'attempted_at', 'sent_at', 'status', 'error_category', 'safe_error_message', 'attempt_number', 'deduplication_key', 'context'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'sent_at' => 'datetime', 'context' => 'array'];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }

    public function serviceIncident(): BelongsTo
    {
        return $this->belongsTo(ServiceIncident::class);
    }
}
