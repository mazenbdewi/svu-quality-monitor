<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class SslCertificateExpiring implements ShouldDispatchAfterCommit
{
    public function __construct(public int $checkId, public int $threshold) {}
}
