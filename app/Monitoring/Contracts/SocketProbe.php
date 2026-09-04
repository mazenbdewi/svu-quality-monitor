<?php

namespace App\Monitoring\Contracts;

interface SocketProbe
{
    /** @return array{success: bool, error_type: ?string, error_message: ?string} */
    public function connect(string $hostname, int $port, int $timeoutSeconds): array;
}
