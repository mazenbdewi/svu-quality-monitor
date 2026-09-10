<?php

namespace App\Models;

use App\Enums\MonitoringCheckType;
use App\Services\MaintenanceWindowService;
use Database\Factories\MonitoredServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MonitoredService extends Model
{
    /** @use HasFactory<MonitoredServiceFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $attributes = [
        'check_type' => 'http',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'check_type',
        'check_config',
        'category',
        'expected_status_code',
        'expected_keyword',
        'check_interval_minutes',
        'warning_response_ms',
        'critical_response_ms',
        'is_active',
        'sla_enabled',
        'sla_target_percent',
        'notifications_enabled',
        'operational_state',
        'failure_confirmation_count',
        'recovery_confirmation_count',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sla_enabled' => 'boolean',
            'sla_target_percent' => 'decimal:2',
            'notifications_enabled' => 'boolean',
            'is_flapping' => 'boolean',
            'first_failure_at' => 'datetime',
            'recovery_started_at' => 'datetime',
            'state_transition_window_started_at' => 'datetime',
            'check_type' => MonitoringCheckType::class,
            'check_config' => 'encrypted:array',
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

    public function slaMetrics(): HasMany
    {
        return $this->hasMany(SlaMetric::class);
    }

    public function latestSlaMetric(): HasOne
    {
        return $this->hasOne(SlaMetric::class)->latestOfMany('period_start');
    }

    public function maintenanceWindows(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceWindow::class, 'maintenance_window_monitored_service');
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

        if ($this->hasOpenIncident()) {
            return in_array($this->operational_state, ['pending_failure', 'down', 'recovering'], true)
                ? $this->operational_state
                : ($latestCheck->is_success ? 'recovering' : 'down');
        }

        if ($this->is_under_maintenance) {
            return 'maintenance';
        }

        if (in_array($this->operational_state, ['pending_failure', 'down', 'recovering'], true)) {
            return $this->operational_state;
        }

        if ($latestCheck->is_success === false) {
            return 'down';
        }

        if ($latestCheck->performance_status === 'critical') {
            return 'critical';
        }

        if ($latestCheck->performance_status === 'warning' || ($latestCheck->is_success === true && $latestCheck->is_slow === true)) {
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
            'critical' => 'danger',
            'pending_failure', 'recovering' => 'warning',
            'maintenance' => 'gray',
            'down' => 'danger',
            default => 'gray',
        };
    }

    public function getCurrentStatusIconAttribute(): string
    {
        return match ($this->current_status) {
            'healthy' => 'heroicon-o-check-circle',
            'slow' => 'heroicon-o-exclamation-triangle',
            'critical' => 'heroicon-o-exclamation-circle',
            'pending_failure', 'recovering', 'maintenance' => 'heroicon-o-wrench-screwdriver',
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

        if ($latestCheck->performance_status === 'critical') {
            return 'critical';
        }

        if ($latestCheck->performance_status === 'warning' || $latestCheck->is_slow === true) {
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

    public function getIsUnderMaintenanceAttribute(): bool
    {
        return app(MaintenanceWindowService::class)->isUnderMaintenance($this);
    }

    public function timeoutSeconds(): int
    {
        return max(1, min((int) (($this->check_config ?? [])['timeout_seconds'] ?? 10), 60));
    }

    public function targetHost(): string
    {
        $target = (string) (($this->check_config ?? [])['hostname'] ?? $this->url);

        return parse_url($target, PHP_URL_HOST) ?: preg_replace('#^https?://#', '', $target);
    }

    public function port(int $default): int
    {
        return max(1, min((int) (($this->check_config ?? [])['port'] ?? $default), 65535));
    }
}
