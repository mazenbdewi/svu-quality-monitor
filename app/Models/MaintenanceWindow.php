<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MaintenanceWindow extends Model
{
    protected $fillable = [
        'name', 'description', 'starts_at', 'ends_at', 'applies_to_all_services', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'applies_to_all_services' => 'boolean',
        ];
    }

    public function monitoredServices(): BelongsToMany
    {
        return $this->belongsToMany(MonitoredService::class, 'maintenance_window_monitored_service');
    }

    public function serviceChecks(): HasMany
    {
        return $this->hasMany(ServiceCheck::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveAt(Builder $query, Carbon $at): Builder
    {
        return $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    public function statusAt(?Carbon $at = null): string
    {
        $at ??= now();

        return $at->lt($this->starts_at) ? 'scheduled' : ($at->lt($this->ends_at) ? 'active' : 'completed');
    }

    public function durationMinutes(): int
    {
        return max(0, (int) ceil($this->starts_at->diffInSeconds($this->ends_at, false) / 60));
    }
}
