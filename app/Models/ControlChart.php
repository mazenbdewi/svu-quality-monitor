<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ControlChart extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'monitored_service_id',
        'chart_type',
        'metric_name',
        'period_type',
        'period_start',
        'period_end',
        'center_line',
        'ucl',
        'lcl',
        'points_count',
        'out_of_control_count',
        'calculated_at',
        'notes',
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
            'center_line' => 'decimal:4',
            'ucl' => 'decimal:4',
            'lcl' => 'decimal:4',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(ControlChartPoint::class);
    }
}
