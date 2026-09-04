<?php

namespace App\Console\Commands;

use App\Services\BackgroundHealthAlertService;
use Illuminate\Console\Command;

class CheckBackgroundHealth extends Command
{
    protected $signature = 'system:check-background-health';

    protected $description = 'Detect transitions in scheduler and queue worker health.';

    public function handle(BackgroundHealthAlertService $alerts): int
    {
        $alerts->check();
        $this->info('Background health checked.');

        return self::SUCCESS;
    }
}
