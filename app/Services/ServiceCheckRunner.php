<?php

namespace App\Services;

use App\Enums\MonitoringCheckType;
use App\Events\SslCertificateExpiring;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Monitoring\CheckResult;
use App\Monitoring\ServiceCheckerRegistry;
use App\Services\Spc\SafePhaseTwoDispatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ServiceCheckRunner
{
    public function __construct(private ServiceCheckerRegistry $checkers) {}

    public function run(MonitoredService $service, string $source = ServiceCheck::SOURCE_MANUAL): ServiceCheck
    {
        $type = $service->check_type instanceof MonitoringCheckType
            ? $service->check_type
            : MonitoringCheckType::tryFrom((string) $service->check_type);

        if ($type === null) {
            throw new InvalidArgumentException('Unsupported monitoring check type.');
        }

        return $this->persist($service, $this->checkers->for($type)->check($service), $source, $type);
    }

    public function persist(
        MonitoredService $service,
        CheckResult $result,
        string $source = ServiceCheck::SOURCE_MANUAL,
        ?MonitoringCheckType $type = null,
    ): ServiceCheck {
        return DB::transaction(function () use ($service, $result, $source, $type): ServiceCheck {
            $performance = $result->performanceStatus ?? $this->performanceStatus($service, $result);
            $maintenance = app(MaintenanceWindowService::class)->activeWindowFor($service, $result->checkedAt);
            $check = $service->serviceChecks()->create([
                'checked_at' => $result->checkedAt,
                'source' => $source,
                'is_during_maintenance' => $maintenance !== null,
                'maintenance_window_id' => $maintenance?->id,
                'check_type' => ($type ?? $service->check_type ?? MonitoringCheckType::Http)->value,
                'status_code' => $result->statusCode,
                'response_time_ms' => $result->responseTimeMs,
                'is_success' => $result->isSuccess,
                'is_slow' => $result->isSuccess && in_array($performance, ['warning', 'critical'], true) && $result->responseTimeMs !== null,
                'performance_status' => $performance,
                'error_type' => $result->errorType,
                'error_message' => $result->errorMessage,
                'expected_keyword_found' => $result->expectedKeywordFound,
                'metadata' => $result->metadata,
            ]);

            app(IncidentDetector::class)->evaluate($service, $check);
            if ($check->check_type === MonitoringCheckType::Ssl->value && $check->is_success && config('monitoring.notifications.ssl.enabled', true)) {
                foreach (config('monitoring.notifications.ssl.thresholds', [30, 14, 7, 3, 1]) as $threshold) {
                    if ((int) data_get($check->metadata, 'days_remaining', PHP_INT_MAX) <= (int) $threshold) {
                        event(new SslCertificateExpiring($check->id, (int) $threshold));
                    }
                }
            }

            if ($source === ServiceCheck::SOURCE_AUTOMATIC) {
                DB::afterCommit(fn () => app(SafePhaseTwoDispatch::class)->dispatch($service->id));
            }

            return $check;
        });
    }

    private function performanceStatus(MonitoredService $service, CheckResult $result): ?string
    {
        if (! $result->isSuccess || $result->responseTimeMs === null) {
            return null;
        }
        if ($result->responseTimeMs >= (int) $service->critical_response_ms) {
            return 'critical';
        }
        if ($result->responseTimeMs >= (int) $service->warning_response_ms) {
            return 'warning';
        }

        return 'healthy';
    }
}
