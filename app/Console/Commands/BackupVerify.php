<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupVerify extends Command
{
    protected $signature = 'backup:verify {path} {--actor-email=}';

    protected $description = 'Verify every file, size and SHA-256 in a backup bundle';

    public function handle(BackupService $backups): int
    {
        try {
            $actor = $this->option('actor-email') ? User::where('email', $this->option('actor-email'))->firstOrFail() : null;
            $result = $backups->verify($this->argument('path'), $actor);
            $this->info('Verified; bytes='.$result['size']);

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup verification failed.');

            return self::FAILURE;
        }
    }
}
