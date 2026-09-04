<?php

namespace App\Monitoring\Checkers;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Monitoring\Contracts\ServiceChecker;
use App\Monitoring\Contracts\SocketProbe;

class TcpServiceChecker implements ServiceChecker
{
    public function __construct(private SocketProbe $probe) {}

    public function type(): MonitoringCheckType
    {
        return MonitoringCheckType::Tcp;
    }

    public function check(MonitoredService $service): CheckResult
    {
        $timer = hrtime(true);
        $host = $service->targetHost();
        $port = $service->port(80);
        $result = $this->probe->connect($host, $port, $service->timeoutSeconds());

        return new CheckResult($result['success'], now(), responseTimeMs: (int) round((hrtime(true) - $timer) / 1_000_000), errorType: $result['error_type'], errorMessage: $result['error_message'], metadata: ['hostname' => $host, 'port' => $port]);
    }
}
