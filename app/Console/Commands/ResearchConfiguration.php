<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ResearchConfiguration extends Command
{
    protected $signature = 'research:configuration';

    protected $description = 'Print a secret-free research configuration, code fingerprint and clock snapshot as JSON.';

    public function handle(\App\Services\Research\ResearchConfiguration $configuration): int
    {
        $this->line(json_encode($configuration->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
