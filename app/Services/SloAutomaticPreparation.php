<?php

namespace App\Services;

use App\Jobs\PrepareAutomaticSloProposal;
use App\Models\MonitoredService;
use App\Models\SloProposal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Bounded, one-time orchestration; never creates a competing research decision. */
class SloAutomaticPreparation
{
    public function __construct(
        private LatencyPercentileAnalysis $latency,
        private EligibleSyntheticChecks $eligibility,
        private SloCalibrationAnalysis $calibration,
        private SloProposalService $proposals,
    ) {}

    /** @return array{eligible: int, dispatched: int, failed: int} */
    public function dispatchDue(): array
    {
        $window = $this->latency->window();
        $counts = ['eligible' => 0, 'dispatched' => 0, 'failed' => 0];

        MonitoredService::query()->where('is_active', true)->whereIn('check_type', ['http', 'api'])
            ->whereDoesntHave('sloProposals')->lazyById()->each(function (MonitoredService $service) use ($window, &$counts): void {
                $attemptKey = self::attemptKey($service->id, $window['end']->toIso8601String());
                $gateAcquired = false;
                try {
                    if (! $this->hasMinimumSamples($service, $window['start'], $window['end'])) {
                        return;
                    }
                    $counts['eligible']++;
                    if (! Cache::add($attemptKey, true, now()->addHours(25))) {
                        return;
                    }
                    $gateAcquired = true;
                    Cache::put(self::statusKey($service->id), $window['end']->toIso8601String(), now()->addHours(2));
                    PrepareAutomaticSloProposal::dispatch(
                        $service->id, $window['end']->toIso8601String(), $window['timezone'], SloCalibrationAnalysis::POLICY_VERSION,
                        $this->calibration->configurationFingerprint($service, $window['timezone'])
                    );
                    $counts['dispatched']++;
                } catch (Throwable $exception) {
                    if ($gateAcquired) {
                        try {
                            Cache::forget(self::statusKey($service->id));
                            Cache::forget($attemptKey);
                        } catch (Throwable $cleanupException) {
                            Log::warning('Automatic SLO dispatch cache cleanup failed.', [
                                'monitored_service_id' => $service->id, 'exception_class' => $cleanupException::class,
                            ]);
                        }
                    }
                    $counts['failed']++;
                    Log::error('Automatic SLO proposal dispatch failed.', [
                        'monitored_service_id' => $service->id, 'exception_class' => $exception::class,
                    ]);
                }
            });

        return $counts;
    }

    public function prepareOne(int $serviceId, string $cutoff, string $timezone, string $policyVersion, string $configurationFingerprint): ?SloProposal
    {
        $window = $this->latency->window();
        if ($policyVersion !== SloCalibrationAnalysis::POLICY_VERSION || $timezone !== $window['timezone']
            || $cutoff !== $window['end']->toIso8601String()) {
            // A delayed job must not silently move its immutable calibration cutoff.
            return null;
        }

        return DB::transaction(function () use ($serviceId, $window, $configurationFingerprint): ?SloProposal {
            $service = MonitoredService::query()->lockForUpdate()->find($serviceId);
            if (! $service || ! $service->is_active || ! in_array($service->check_type->value, ['http', 'api'], true)
                || SloProposal::query()->where('monitored_service_id', $serviceId)->exists()
                || ! hash_equals($configurationFingerprint, $this->calibration->configurationFingerprint($service, $window['timezone']))
                || ! $this->hasMinimumSamples($service, $window['start'], $window['end'])) {
                return null;
            }
            $coverage = $this->latency->coverageForPeriod($service, $window['start'], $window['end']);
            if (($coverage['coverage_percent'] ?? 0) < SloCalibrationAnalysis::MINIMUM_COVERAGE_PERCENT) {
                return null;
            }

            return $this->proposals->prepareAutomatically($service);
        }, 3);
    }

    public static function statusKey(int $serviceId): string
    {
        return "slo:automatic-preparation:{$serviceId}";
    }

    private static function attemptKey(int $serviceId, string $cutoff): string
    {
        return "slo:automatic-attempt:{$serviceId}:".hash('sha256', $cutoff);
    }

    private function hasMinimumSamples(MonitoredService $service, Carbon $start, Carbon $end): bool
    {
        $minimum = max(2, (int) config('monitoring.spc.exploratory_min_points', 20));
        $maintenance = $this->eligibility->maintenance($service, $start, $end);

        return $this->eligibility->query($service, $start, $end, $maintenance)
            ->where('is_success', true)->whereNotNull('response_time_ms')->where('response_time_ms', '>=', 0)
            ->limit($minimum)->pluck('service_checks.id')->count() >= $minimum;
    }
}
