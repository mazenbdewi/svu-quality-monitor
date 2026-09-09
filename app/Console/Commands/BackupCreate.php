<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCreate extends Command
{
    protected $signature = 'backup:create {--actor-email= : Existing administrative actor for manual audit} {--scheduled : Automated scheduler operation} {--pre-update : Host deployment checkpoint}';

    protected $description = 'Create and verify a private full MySQL/files backup bundle';

    public function handle(BackupService $backups): int
    {
        try {
            $actor = $this->option('actor-email') ? User::where('email', $this->option('actor-email'))->firstOrFail() : null;
            if (! $actor && ! $this->option('scheduled') && ! $this->option('pre-update')) {
                $this->error('Manual backup requires --actor-email for audit.');

                return self::FAILURE;
            }
            $result = $backups->create($actor, $this->option('pre-update') ? 'pre-update' : ($this->option('scheduled') ? 'scheduled' : 'manual'));
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup failed. Check access, disk space and database tooling.');

            return self::FAILURE;
        }
    }
}
