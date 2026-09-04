<?php

namespace App\Monitoring\Contracts;

interface SslCertificateProbe
{
    /** @return array<string, mixed> */
    public function inspect(string $hostname, int $port, int $timeoutSeconds): array;
}
