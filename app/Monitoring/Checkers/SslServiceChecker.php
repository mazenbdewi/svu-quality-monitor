<?php

namespace App\Monitoring\Checkers;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Monitoring\Contracts\ServiceChecker;
use App\Monitoring\Contracts\SslCertificateProbe;
use Carbon\Carbon;
use Throwable;

class SslServiceChecker implements ServiceChecker
{
    public function __construct(private SslCertificateProbe $probe) {}

    public function type(): MonitoringCheckType
    {
        return MonitoringCheckType::Ssl;
    }

    public function check(MonitoredService $service): CheckResult
    {
        $host = $service->targetHost();
        $port = $service->port(443);
        try {
            $certificate = $this->probe->inspect($host, $port, $service->timeoutSeconds());
            $expiresAt = Carbon::createFromTimestamp((int) ($certificate['validTo_time_t'] ?? 0));
            $days = now()->diffInDays($expiresAt, false);
            if ($days < 0) {
                return new CheckResult(false, now(), errorType: 'certificate_expired', errorMessage: 'The TLS certificate has expired.', metadata: $this->metadata($certificate, $expiresAt, $days));
            }

            return new CheckResult(true, now(), performanceStatus: $days <= 30 ? 'warning' : null, metadata: $this->metadata($certificate, $expiresAt, $days));
        } catch (Throwable) {
            return new CheckResult(false, now(), errorType: 'tls_failure', errorMessage: 'The TLS certificate could not be verified.', metadata: ['hostname' => $host, 'port' => $port]);
        }
    }

    private function metadata(array $certificate, Carbon $expiresAt, int $days): array
    {
        return ['issuer' => $certificate['issuer']['CN'] ?? null, 'subject' => $certificate['subject']['CN'] ?? null, 'valid_from' => isset($certificate['validFrom_time_t']) ? Carbon::createFromTimestamp((int) $certificate['validFrom_time_t'])->toIso8601String() : null, 'expires_at' => $expiresAt->toIso8601String(), 'days_remaining' => $days];
    }
}
