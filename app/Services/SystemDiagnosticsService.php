<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\NotificationSetting;
use App\Models\ServiceCheck;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class SystemDiagnosticsService
{
    public function __construct(private SystemHealthService $health, private BackupService $backups) {}

    public function snapshot(): array
    {
        // Each check is isolated: never expose exceptions or configuration objects.
        $definitions = [
            'application' => ['application', true, fn () => $this->application()],
            'database' => ['database', true, fn () => $this->database()],
            'migrations' => ['database', true, fn () => $this->migrations()],
            'scheduler' => ['background', true, fn () => $this->result($this->health->schedulerStatus())],
            'queue' => ['background', true, fn () => $this->queue()],
            'monitoring' => ['monitoring', false, fn () => $this->monitoring()],
            'backup' => ['backup', false, fn () => $this->backup()],
            'backup_ready' => ['backup', false, fn () => $this->backupReady()],
            'storage' => ['storage', true, fn () => $this->result($this->storageWritable() ? 'healthy' : 'down')],
            'cache' => ['storage', true, fn () => $this->cache()],
            'disk' => ['storage', false, fn () => $this->disk()],
            'super_admin' => ['security', true, fn () => $this->result(User::where('is_active', true)->role('super_admin')->exists() ? 'healthy' : 'down')],
            'rbac' => ['security', true, fn () => $this->rbac()],
            'audit' => ['security', true, fn () => $this->audit()],
            'telegram' => ['notifications', false, fn () => $this->notification('telegram')],
            'email' => ['notifications', false, fn () => $this->notification('email')],
        ];
        $checks = [];
        foreach ($definitions as $key => [$group, $critical, $callback]) {
            try {
                $check = $callback();
            } catch (Throwable) {
                $check = $this->result('down', ['availability' => __('diagnostics.unavailable')]);
            }
            $checks[$key] = [...$check, 'group' => $group, 'critical' => $critical];
        }

        return ['status' => $this->overall($checks), 'checked_at' => now()->toIso8601String(), 'checks' => $checks];
    }

    public function overall(array $checks): string
    {
        if (collect($checks)->contains(fn ($check) => $check['critical'] && $check['status'] === 'down')) {
            return 'not_ready';
        }

        return collect($checks)->contains(fn ($check) => $check['status'] !== 'healthy') ? 'attention' : 'ready';
    }

    protected function application(): array
    {
        return $this->result(config('app.env') === 'production' && config('app.debug') ? 'warning' : 'healthy', [
            'environment' => (string) config('app.env'), 'debug' => config('app.debug') ? 'on' : 'off',
            'php' => PHP_VERSION, 'laravel' => app()->version(), 'version' => (string) config('app.version', 'unknown'),
        ]);
    }

    protected function database(): array
    {
        DB::connection()->select('select 1');
        $driver = DB::connection()->getDriverName();
        $version = match ($driver) {
            'mysql', 'pgsql' => DB::selectOne('select version() as version')->version,
            'sqlite' => DB::selectOne('select sqlite_version() as version')->version,
            default => __('diagnostics.unavailable'),
        };

        return $this->result('healthy', ['engine' => $driver, 'version' => $version]);
    }

    protected function migrations(): array
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
        $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        // We cannot infer migration risk safely: all pending migrations prevent readiness.
        return $this->result($pending ? 'down' : 'healthy', ['pending' => $pending]);
    }

    protected function queue(): array
    {
        $status = $this->health->queueStatus();
        $counts = $this->health->queueCounts();
        if ($status === 'healthy' && (($counts['failed_jobs'] ?? 0) > 0 || $counts['pending_jobs'] === null || $counts['failed_jobs'] === null)) {
            $status = 'warning';
        }

        return $this->result($status, $counts);
    }

    protected function monitoring(): array
    {
        $active = MonitoredService::where('is_active', true);
        if (! $active->exists()) {
            return $this->result('healthy', ['activity' => __('diagnostics.no_services')]);
        }
        $last = ServiceCheck::where('source', ServiceCheck::SOURCE_AUTOMATIC)
            ->whereHas('monitoredService', fn ($query) => $query->where('is_active', true))
            ->latest('checked_at')->first(['checked_at'])?->checked_at;
        $graceMinutes = max(1, (int) $active->max('check_interval_minutes')) * 2;

        return $this->result($last && $last->gte(now()->subMinutes($graceMinutes)) ? 'healthy' : 'warning', ['last_automatic' => $last?->toIso8601String()]);
    }

    protected function backup(): array
    {
        $health = $this->backups->health();

        // Only safe summary fields, never raw records, paths or error messages.
        return $this->result($health['status'], [
            'last_success' => $health['success']['finished_at'] ?? null,
            'age_hours' => isset($health['age']) ? round($health['age'], 1) : null,
            'last_failure' => $health['failure']['finished_at'] ?? null,
            'verification' => $health['success'] ? __('diagnostics.verified_on_creation') : __('diagnostics.unavailable'),
        ]);
    }

    protected function backupReady(): array
    {
        $commands = app(Kernel::class)->all();
        $ready = filled(config('backup.files')) && filled(config('backup.mysql')) && filled(config('backup.mysqldump'))
            && is_dir(config('backup.directory')) && is_writable(config('backup.directory'));
        foreach (['backup:create', 'backup:verify', 'backup:restore'] as $command) {
            $ready = $ready && isset($commands[$command]);
        }

        return $this->result($ready ? 'healthy' : 'warning');
    }

    protected function storageWritable(): bool
    {
        return is_writable(storage_path('app')) && is_writable(storage_path('framework'));
    }

    protected function cache(): array
    {
        $key = 'diagnostics:'.Str::uuid();
        try {
            Cache::put($key, 'ok', 60);
            $ready = Cache::get($key) === 'ok' && is_writable(storage_path('framework/cache'));

            return $this->result($ready ? 'healthy' : 'down');
        } finally {
            Cache::forget($key);
        }
    }

    protected function disk(): array
    {
        $values = [];
        $status = 'healthy';
        foreach (['storage_gb' => storage_path(), 'backup_gb' => config('backup.directory')] as $key => $path) {
            $free = is_dir($path) ? @disk_free_space($path) : false;
            $values[$key] = $free === false ? null : round($free / 1073741824, 2);
            if ($free === false || $free < config('diagnostics.minimum_free_bytes')) {
                $status = 'warning';
            }
        }

        return $this->result($status, $values);
    }

    protected function rbac(): array
    {
        $roles = Role::where('guard_name', 'web')->whereIn('name', ['super_admin', 'administrator', 'operator', 'viewer'])->count();
        $permission = Permission::findByName('operations.view', 'web');
        $working = $roles === 4 && Role::findByName('operator', 'web')->hasPermissionTo($permission);

        return $this->result($working ? 'healthy' : 'down');
    }

    protected function audit(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->result('down');
        }
        DB::table('audit_logs')->limit(1)->first(['id']);

        return $this->result('healthy');
    }

    protected function notification(string $channel): array
    {
        // current() creates a record; diagnostics must remain read-only.
        $setting = NotificationSetting::query()->first();
        if (! $setting?->getAttribute($channel.'_enabled')) {
            return $this->result('healthy', ['configuration' => __('diagnostics.disabled')]);
        }
        $configured = $channel === 'telegram'
            ? filled($setting->telegram_bot_token) && filled($setting->telegram_chat_id)
            : collect($setting->email_recipients ?? [])->contains(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) && $this->mailConfigured();

        return $this->result($configured ? 'healthy' : 'warning', ['configuration' => __('diagnostics.'.($configured ? 'configured' : 'not_configured'))]);
    }

    private function mailConfigured(): bool
    {
        $mailer = config('mail.mailers.'.config('mail.default'), []);

        // Configuration-only inspection; unsupported transports are not claimed ready.
        return ($mailer['transport'] ?? null) === 'smtp'
            && (filled($mailer['url'] ?? null) || (filled($mailer['host'] ?? null) && filled($mailer['port'] ?? null)))
            && (blank($mailer['username'] ?? null) || filled($mailer['password'] ?? null))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function result(string $status, array $details = []): array
    {
        return compact('status', 'details');
    }
}
