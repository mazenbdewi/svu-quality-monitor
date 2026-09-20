<?php

namespace App\Services\Baselines;

use App\Models\AuditLog;
use App\Models\MonitoredService;
use Carbon\Carbon;

class BaselineConfiguration
{
    public function snapshot(MonitoredService $service, string $timezone, string $aggregation, string $method): array
    {
        $safe = [
            'request_method' => $service->check_type->value === 'api' && strtoupper((string) data_get($service->check_config, 'method', 'GET')) === 'POST' ? 'POST' : 'GET',
            'timeout_seconds' => $service->timeoutSeconds(),
            'measurement_configuration_fingerprint' => hash_hmac('sha256', ResearchSnapshotEncoding::encode($service->check_config ?? []), (string) config('app.key')),
            'service_type' => $service->check_type->value,
            'check_interval_minutes' => (int) $service->check_interval_minutes,
            'warning_response_ms' => (int) $service->warning_response_ms,
            'critical_response_ms' => (int) $service->critical_response_ms,
            'expected_status_code' => (int) $service->expected_status_code,
            'endpoint_fingerprint' => hash_hmac('sha256', (string) $service->url, (string) config('app.key')),
            'expected_keyword_fingerprint' => hash_hmac('sha256', (string) $service->expected_keyword, (string) config('app.key')),
            'eligibility_policy_version' => 'r1-research+r4a-record-cutoff-v1',
            'analysis_timezone' => $timezone, 'aggregation_interval' => $aggregation,
            'method_identifier' => $method, 'calculation_version' => 'spc-phase1-r4a-v1',
        ];

        return $safe;
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', ResearchSnapshotEncoding::encode($snapshot));
    }

    public function changes(MonitoredService $service, Carbon $start, Carbon $end): array
    {
        $fields = ['check_type', 'check_interval_minutes', 'warning_response_ms', 'critical_response_ms', 'expected_status_code'];

        return AuditLog::query()->where('auditable_type', MonitoredService::class)->where('auditable_id', $service->id)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderBy('id')->get()
            ->filter(fn ($log) => array_intersect($fields, array_keys($log->after ?? [])) || data_get($log->context, 'endpoint_changed') || data_get($log->context, 'sensitive_monitoring_configuration_changed') || data_get($log->context, 'other_configuration_changed'))
            ->map(fn ($log) => ['audit_id' => $log->id, 'at' => $log->created_at->toIso8601String(), 'fields' => array_values(array_intersect($fields, array_keys($log->after ?? []))), 'endpoint_changed' => (bool) data_get($log->context, 'endpoint_changed'), 'other_measurement_configuration_changed' => (bool) (data_get($log->context, 'sensitive_monitoring_configuration_changed') || data_get($log->context, 'other_configuration_changed'))])->values()->all();
    }
}
