<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore {path} {--force} {--workers-stopped : Confirm scheduler/queue and other writers are stopped} {--actor-email= : Existing active Super Admin}';

    protected $description = 'Restore a trusted backup; requires explicit offline acknowledgement and leaves maintenance enabled';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') || ! $this->option('workers-stopped') || ! $this->option('actor-email')) {
            $this->error('Restore requires --force --workers-stopped --actor-email after stopping all writers.');

            return self::FAILURE;
        }
        try {
            $actor = User::where('email', $this->option('actor-email'))->firstOrFail();
            $backups->restore($this->argument('path'), $actor);
            $this->info('Restored. Maintenance remains enabled; restart workers, run smoke checks, then artisan up.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Restore rejected or failed. Inspect maintenance state; do not reopen until verified.');

            return self::FAILURE;
        }
    }
}
