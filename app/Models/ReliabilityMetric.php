<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliabilityMetric extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'monitored_service_id',
        'period_type',
        'period_start',
        'period_end',
        'total_checks',
        'successful_checks',
        'failed_checks',
        'incidents_count',
        'uptime_minutes',
        'downtime_minutes',
        'planned_maintenance_minutes',
        'observation_minutes',
        'availability_percent',
        'mtbf_minutes',
        'mttr_minutes',
        'failure_rate',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'calculated_at' => 'datetime',
            'availability_percent' => 'decimal:4',
            'mtbf_minutes' => 'decimal:2',
            'mttr_minutes' => 'decimal:2',
            'failure_rate' => 'decimal:8',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
