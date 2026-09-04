<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class SystemHealthProblemDetected implements ShouldDispatchAfterCommit
{
    public function __construct(public string $component, public string $cycle) {}
}
