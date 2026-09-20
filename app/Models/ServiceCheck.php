<?php

namespace App\Models;

use Database\Factories\ServiceCheckFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /** Research provenance: diagnostic fixtures must carry an explicit metadata marker. */
    public function scopeAutomaticObservations(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_AUTOMATIC)
            ->where(function (Builder $query): void {
                $query->whereNull('metadata->is_synthetic')->orWhere('metadata->is_synthetic', false);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('metadata->is_diagnostic')->orWhere('metadata->is_diagnostic', false);
            });
    }

    /** Binary outcome sample, including failures without a completed response. */
    public function scopeResearchChecks(Builder $query): Builder
    {
        return $query->automaticObservations()
            ->whereIn('check_type', ['http', 'api'])
            ->where('is_during_maintenance', false);
    }

    /** Completed, functionally successful responses; failure durations remain diagnostic. */
    public function scopeResearchResponseTimes(Builder $query): Builder
    {
        return $query->researchChecks()->where('is_success', true)
            ->whereNotNull('response_time_ms')->where('response_time_ms', '>=', 0);
    }

    public function getIsProblematicAttribute(): bool
    {
        return ! $this->is_success || $this->is_slow;
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
