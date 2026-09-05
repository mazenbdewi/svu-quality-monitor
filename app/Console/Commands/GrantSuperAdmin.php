<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class GrantSuperAdmin extends Command
{
    protected $signature = 'users:grant-super-admin {email : Existing user email}';

    protected $description = 'Grant the super_admin role to an existing user and activate the account.';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user exists with that email.');

            return self::FAILURE;
        }
        Role::findOrCreate('super_admin', 'web');
        $user->update(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->info("Super Admin granted to {$user->email}.");

        return self::SUCCESS;
    }
}
