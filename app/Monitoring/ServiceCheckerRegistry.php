<?php

namespace App\Monitoring;

use App\Enums\MonitoringCheckType;
use App\Monitoring\Checkers\ApiServiceChecker;
use App\Monitoring\Checkers\DnsServiceChecker;
use App\Monitoring\Checkers\HttpServiceChecker;
use App\Monitoring\Checkers\SslServiceChecker;
use App\Monitoring\Checkers\TcpServiceChecker;
use App\Monitoring\Contracts\ServiceChecker;
use InvalidArgumentException;

class ServiceCheckerRegistry
{
    /** @param iterable<ServiceChecker> $checkers */
    public function __construct(iterable $checkers = [])
    {
        $this->checkers = $checkers === [] ? [app(HttpServiceChecker::class), app(ApiServiceChecker::class), app(DnsServiceChecker::class), app(SslServiceChecker::class), app(TcpServiceChecker::class)] : $checkers;
    }

    /** @var iterable<ServiceChecker> */
    private iterable $checkers;

    public function for(MonitoringCheckType $type): ServiceChecker
    {
        foreach ($this->checkers as $checker) {
            if ($checker->type() === $type) {
                return $checker;
            }
        } throw new InvalidArgumentException("Unsupported monitoring check type [{$type->value}].");
    }
}
