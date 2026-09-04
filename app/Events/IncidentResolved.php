<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class IncidentResolved implements ShouldDispatchAfterCommit
{
    public function __construct(public int $incidentId) {}
}
