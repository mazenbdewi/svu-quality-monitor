<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SystemHealthService;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SystemSmokeCheck extends Command
{
    protected $signature = 'system:smoke-check {--static : Skip heartbeat checks while workers are deliberately stopped}';

    protected $description = 'Check local deployment health without external monitoring requests';

    public function handle(SystemHealthService $health): int
    {
        try {
            DB::select('select 1');
            $tables = collect(['users', 'migrations', 'roles', 'permissions', 'audit_logs', 'service_incidents'])->every(fn ($table) => Schema::hasTable($table));
            $pending = array_diff(array_map(fn ($file) => basename($file, '.php'), glob(database_path('migrations/*.php'))), DB::table('migrations')->pluck('migration')->all());
            $key = 'smoke:'.Str::uuid();
            Cache::put($key, 'ok', 60);
            $cache = Cache::get($key) === 'ok';
            Cache::forget($key);
            $storage = is_writable(storage_path('app')) && is_writable(storage_path('framework'));
            $admin = User::where('is_active', true)->role('super_admin')->get()->contains(fn ($u) => $u->canAccessPanel(Filament::getPanel('admin')) && $u->can('users.view'));
            $checks = ['database' => true, 'schema' => $tables, 'migrations' => $pending === [], 'cache' => $cache, 'storage' => $storage, 'active_super_admin' => $admin];
            if (! $this->option('static')) {
                $checks += ['scheduler' => $health->schedulerStatus() === 'healthy', 'queue' => $health->queueStatus() === 'healthy'];
            }
            $this->line(json_encode($checks));

            return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Smoke check failed: database/schema/runtime unavailable.');

            return self::FAILURE;
        }
    }
}
