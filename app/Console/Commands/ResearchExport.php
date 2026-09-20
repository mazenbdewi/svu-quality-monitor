<?php

namespace App\Console\Commands;

use App\Services\Research\ResearchPackage;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ResearchExport extends Command
{
    protected $signature = 'research:export {--from= : Inclusive ISO-8601 start} {--to= : Exclusive ISO-8601 end} {--output= : New directory for JSON and CSV files}';

    protected $description = 'Archive a reproducible, secret-free research package without changing database records.';

    public function handle(ResearchPackage $package): int
    {
        foreach (['from', 'to', 'output'] as $key) {
            if (! $this->option($key)) {
                $this->error('Required: --from, --to and --output');

                return self::FAILURE;
            }
        }
        try {
            $manifest = $package->export(Carbon::parse($this->option('from'), 'UTC'), Carbon::parse($this->option('to'), 'UTC'), $this->option('output'));
        } catch (\Throwable $error) {
            $this->error('Export incomplete: '.$error->getMessage());

            return self::FAILURE;
        }
        $this->info('Research package complete: '.count($manifest['sha256']).' files plus manifest.');

        return self::SUCCESS;
    }
}
