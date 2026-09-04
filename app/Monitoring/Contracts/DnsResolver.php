<?php

namespace App\Monitoring\Contracts;

interface DnsResolver
{
    /** @return list<array<string, mixed>> */
    public function resolve(string $hostname, string $recordType): array;
}
