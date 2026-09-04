<?php

namespace App\Models;

use Database\Factories\ServiceCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCheck extends Model
{
    /** @use HasFactory<ServiceCheckFactory> */
    use HasFactory;

    public const SOURCE_AUTOMATIC = 'automatic';

    public const SOURCE_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monitored_service_id',
        'checked_at',
        'source',
        'is_during_maintenance',
        'maintenance_window_id',
        'check_type',
        'status_code',
        'response_time_ms',
        'is_success',
        'is_slow',
        'performance_status',
        'error_type',
        'error_message',
        'expected_keyword_found',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'is_success' => 'boolean',
            'is_slow' => 'boolean',
            'is_during_maintenance' => 'boolean',
            'expected_keyword_found' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }

    public function maintenanceWindow(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWindow::class);
    }
}
