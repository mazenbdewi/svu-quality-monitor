<?php

namespace App\Models;

use Database\Factories\MonitoredServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MonitoredService extends Model
{
    /** @use HasFactory<MonitoredServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'category',
        'expected_status_code',
        'expected_keyword',
        'check_interval_minutes',
        'warning_response_ms',
        'critical_response_ms',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function serviceChecks(): HasMany
    {
        return $this->hasMany(ServiceCheck::class);
    }

    public function latestServiceCheck(): HasOne
    {
        return $this->hasOne(ServiceCheck::class)->latestOfMany('checked_at');
    }

    public function serviceIncidents(): HasMany
    {
        return $this->hasMany(ServiceIncident::class);
    }

    public function reliabilityMetrics(): HasMany
    {
        return $this->hasMany(ReliabilityMetric::class);
    }

    public function controlCharts(): HasMany
    {
        return $this->hasMany(ControlChart::class);
    }

    public function openIncident(): HasOne
    {
        return $this->hasOne(ServiceIncident::class)
            ->where('status', 'open')
            ->latestOfMany('started_at');
    }

    public function currentStatus(): string
    {
        $latestCheck = $this->latestServiceCheck;

        if (! $latestCheck) {
            return 'unknown';
        }

        if ($latestCheck->is_success === false) {
            return 'down';
        }

        if ($latestCheck->is_success === true && $latestCheck->is_slow === true) {
            return 'slow';
        }

        if ($latestCheck->is_success === true && $latestCheck->is_slow === false) {
            return 'healthy';
        }

        return 'unknown';
    }

    public function getCurrentStatusAttribute(): string
    {
        return $this->currentStatus();
    }

    public function hasOpenIncident(): bool
    {
        if ($this->relationLoaded('openIncident')) {
            return $this->openIncident !== null;
        }

        return $this->openIncident()->exists();
    }

    public function getHasOpenIncidentAttribute(): bool
    {
        return $this->hasOpenIncident();
    }

    public function getCurrentStatusLabelAttribute(): string
    {
        return __("monitoring.service_status.statuses.{$this->current_status}");
    }

    public function getCurrentStatusColorAttribute(): string
    {
        return match ($this->current_status) {
            'healthy' => 'success',
            'slow' => 'warning',
            'down' => 'danger',
            default => 'gray',
        };
    }

    public function getCurrentStatusIconAttribute(): string
    {
        return match ($this->current_status) {
            'healthy' => 'heroicon-o-check-circle',
            'slow' => 'heroicon-o-exclamation-triangle',
            'down' => 'heroicon-o-x-circle',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    public function getLastProblemTypeAttribute(): ?string
    {
        $latestCheck = $this->latestServiceCheck;

        if (! $latestCheck) {
            return null;
        }

        if ($latestCheck->is_success === false) {
            return $latestCheck->error_type ?: 'unknown';
        }

        if ($latestCheck->is_slow === true) {
            return 'slow';
        }

        return null;
    }

    public function getLastProblemTypeLabelAttribute(): string
    {
        $problemType = $this->last_problem_type;

        if (! $problemType) {
            return '-';
        }

        $translationKey = "monitoring.service_status.problem_types.{$problemType}";
        $translation = __($translationKey);

        return $translation === $translationKey ? $problemType : $translation;
    }
}
