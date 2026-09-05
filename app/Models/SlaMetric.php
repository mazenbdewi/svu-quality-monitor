<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaMetric extends Model
{
    protected $fillable = [
        'monitored_service_id', 'period_start', 'period_end', 'target_percent',
        'eligible_observation_seconds', 'planned_maintenance_seconds', 'unplanned_downtime_seconds',
        'availability_percent', 'allowed_downtime_seconds', 'error_budget_consumed_seconds',
        'error_budget_remaining_seconds', 'error_budget_consumed_percent', 'status', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime', 'period_end' => 'datetime', 'calculated_at' => 'datetime',
            'target_percent' => 'decimal:2', 'availability_percent' => 'decimal:6',
            'allowed_downtime_seconds' => 'decimal:6', 'error_budget_remaining_seconds' => 'decimal:6',
            'error_budget_consumed_percent' => 'decimal:6',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
