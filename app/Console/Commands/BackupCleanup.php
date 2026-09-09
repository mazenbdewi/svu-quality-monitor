<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCleanup extends Command
{
    protected $signature = 'backup:cleanup {--actor-email=} {--scheduled}';

    protected $description = 'Apply retention to verified known backups in the dedicated directory';

    public function handle(BackupService $backups): int
    {
        try {
            $actor = $this->option('actor-email') ? User::where('email', $this->option('actor-email'))->firstOrFail() : null;
            if (! $actor && ! $this->option('scheduled')) {
                return self::FAILURE;
            }
            $this->info('Removed '.count($backups->cleanup($actor)).' expired backups.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Cleanup failed. No unknown backups will be removed.');

            return self::FAILURE;
        }
    }
}
