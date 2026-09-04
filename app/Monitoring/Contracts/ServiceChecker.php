<?php

namespace App\Monitoring\Contracts;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;

interface ServiceChecker
{
    public function type(): MonitoringCheckType;

    public function check(MonitoredService $service): CheckResult;
}
