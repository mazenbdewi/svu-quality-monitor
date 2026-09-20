<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'measurement_context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measurement_context' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'calculated_at' => 'datetime',
            'availability_percent' => 'decimal:4',
            'mtbf_minutes' => 'decimal:2',
            'mttr_minutes' => 'decimal:2',
            'failure_rate' => 'decimal:8',
        ];
    }

    /** Weighted observed service-time, never an end-to-end system availability. */
    public static function weightedAvailability(Builder $query): ?float
    {
        $metrics = (clone $query)->get()->filter(fn ($metric) => data_get($metric->measurement_context, 'status') === 'observed');
        $periods = [];
        $eligible = 0;
        $uptime = 0;
        foreach ($metrics as $metric) {
            $context = $metric->measurement_context;
            $start = $context['observation_start'];
            $end = $context['observation_end'];
            foreach ($periods[$metric->monitored_service_id] ?? [] as [$oldStart, $oldEnd]) {
                if ($start < $oldEnd && $end > $oldStart) {
                    return null; // Ambiguous overlapping daily/weekly/custom snapshots must not be double-weighted.
                }
            }
            $periods[$metric->monitored_service_id][] = [$start, $end];
            $eligible += $context['eligible_seconds'];
            $uptime += $context['uptime_seconds'];
        }

        return $eligible > 0 ? 100 * $uptime / $eligible : null;
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
