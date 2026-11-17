<?php

namespace App\Services;

use App\Enums\MonitoringCheckType;
use App\Events\SslCertificateExpiring;
use App\Models\MonitoredService;
use App\Models\MonitoringOpportunity;
use App\Models\ServiceCheck;
use App\Monitoring\CheckResult;
use App\Monitoring\ServiceCheckerRegistry;
use App\Services\Spc\SafePhaseTwoDispatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ServiceCheckRunner
{
    public function __construct(private ServiceCheckerRegistry $checkers) {}

    public function run(MonitoredService $service, string $source = ServiceCheck::SOURCE_MANUAL, ?int $opportunityId = null, ?string $claimToken = null): ServiceCheck
    {
        $type = $service->check_type instanceof MonitoringCheckType
            ? $service->check_type
            : MonitoringCheckType::tryFrom((string) $service->check_type);

        if ($type === null) {
            throw new InvalidArgumentException('Unsupported monitoring check type.');
        }

        return $this->persist($service, $this->checkers->for($type)->check($service), $source, $type, $opportunityId, $claimToken);
    }

    public function persist(
        MonitoredService $service,
        CheckResult $result,
        string $source = ServiceCheck::SOURCE_MANUAL,
        ?MonitoringCheckType $type = null,
        ?int $opportunityId = null,
        ?string $claimToken = null,
    ): ServiceCheck {
        return DB::transaction(function () use ($service, $result, $source, $type, $opportunityId, $claimToken): ServiceCheck {
            $opportunity = $opportunityId === null ? null : MonitoringOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
            if ($opportunity && ($source !== ServiceCheck::SOURCE_AUTOMATIC || $opportunity->monitored_service_id !== $service->id
                || $opportunity->status !== 'running' || ! hash_equals((string) $opportunity->claim_token, (string) $claimToken)
                || $opportunity->epoch->effective_until_utc !== null || ! config('monitoring.fixed_cadence.enabled'))) {
                throw new LogicException('Monitoring opportunity claim is no longer valid.');
            }
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

            if ($opportunity) {
                $opportunity->update([
                    'status' => 'completed',
                    'service_check_id' => $check->id,
                    'completed_at_utc' => now()->utc(),
                ]);
            }

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
