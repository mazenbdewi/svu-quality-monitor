<?php

namespace App\Monitoring\Support;

use App\Monitoring\Contracts\DnsResolver;

class NativeDnsResolver implements DnsResolver
{
    public function resolve(string $hostname, string $recordType): array
    {
        $type = constant('DNS_'.strtoupper($recordType));

        return @dns_get_record($hostname, $type) ?: [];
    }
}
