<?php

namespace App\Monitoring;

final class MeasurementLimits
{
    public const JOB_TIMEOUT_SECONDS = 30;

    // Leave ten seconds for persistence and incident processing after network I/O.
    public const MAX_SERVICE_TIMEOUT_SECONDS = self::JOB_TIMEOUT_SECONDS - 10;
}
