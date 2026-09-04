<?php

namespace App\Monitoring\Checkers;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Monitoring\Contracts\DnsResolver;
use App\Monitoring\Contracts\ServiceChecker;
use Throwable;

class DnsServiceChecker implements ServiceChecker
{
    public function __construct(private DnsResolver $resolver) {}

    public function type(): MonitoringCheckType
    {
        return MonitoringCheckType::Dns;
    }

    public function check(MonitoredService $service): CheckResult
    {
        $hostname = $service->targetHost();
        $recordType = strtoupper((string) (($service->check_config ?? [])['record_type'] ?? 'A'));
        try {
            $records = $this->resolver->resolve($hostname, $recordType);
            if ($records === []) {
                return new CheckResult(false, now(), errorType: 'dns_failure', errorMessage: 'No DNS records were returned.', metadata: ['hostname' => $hostname, 'record_type' => $recordType]);
            }

            return new CheckResult(true, now(), metadata: ['hostname' => $hostname, 'record_type' => $recordType, 'records' => array_slice($this->values($records), 0, 20)]);
        } catch (Throwable) {
            return new CheckResult(false, now(), errorType: 'dns_failure', errorMessage: 'DNS resolution failed.', metadata: ['hostname' => $hostname, 'record_type' => $recordType]);
        }
    }

    private function values(array $records): array
    {
        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? $record['txt'] ?? null, $records)));
    }
}
