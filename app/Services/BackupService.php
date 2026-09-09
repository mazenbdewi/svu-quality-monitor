<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class BackupService
{
    public function __construct(private BackupDatabase $database) {}

    public function create(?User $actor = null, string $type = 'manual'): array
    {
        $this->authorize($actor, 'backups.create');

        return $this->locked(fn () => $this->createBundle($actor, $type));
    }

    private function createBundle(?User $actor, string $type): array
    {
        if (! in_array($type, ['manual', 'scheduled', 'pre-update', 'pre-restore'], true)) {
            throw new RuntimeException('Invalid backup type.');
        }
        $name = 'backup-'.now()->utc()->format('Ymd-His').'-'.Str::uuid();
        $path = $this->root().'/'.$name;
        $record = ['name' => $name, 'type' => $type, 'started_at' => now()->toIso8601String(), 'status' => 'running'];
        $this->record($record);
        try {
            $db = $this->database->metadata();
            $sources = config('backup.files');
            $filesBytes = 0;
            foreach ($sources as $source) {
                foreach ($this->files($source) as $file) {
                    $filesBytes += filesize($file);
                }
            }
            $needed = config('backup.minimum_free_bytes') + 2 * ($filesBytes + $this->database->estimatedBytes());
            if (disk_free_space($this->root()) < $needed) {
                throw new RuntimeException('Insufficient backup disk space.');
            }
            mkdir($path, 0700);
            $this->database->dump($path.'/database.sql');
            foreach ($sources as $key => $source) {
                $this->copyTree($source, $path.'/files/'.$key);
            }
            $metadata = ['format' => 1, 'application' => 'svu-quality-monitor', 'created_at' => now()->utc()->toIso8601String(),
                'application_version' => (string) config('app.version'), 'laravel_version' => app()->version(),
                'database' => $db, 'components' => ['database', ...array_keys($sources)],
                'file_count' => count($this->files($path.'/files')), 'dump_bytes' => filesize($path.'/database.sql')];
            $this->json($path.'/metadata.json', $metadata);
            $manifest = [];
            foreach ($this->files($path) as $file) {
                $manifest[substr($file, strlen($path) + 1)] = ['size' => filesize($file), 'sha256' => hash_file('sha256', $file)];
            }
            $this->json($path.'/manifest.json', $manifest);
            $verified = $this->verify($path);
            $record += ['finished_at' => now()->toIso8601String(), 'size' => $verified['size']];
            $record['status'] = 'success';
            $this->record($record);
            $this->audit($actor, 'backup.created');

            return ['path' => $path, 'timestamp' => $metadata['created_at'], 'size' => $verified['size'], 'verified' => true];
        } catch (Throwable) {
            $record['status'] = 'failed';
            $record['finished_at'] = now()->toIso8601String();
            $record['error_message_safe'] = 'Backup failed. Check disk, file access and database tooling.';
            $this->record($record);
            Log::error('Backup failed.', ['run' => $name]);
            throw new RuntimeException($record['error_message_safe']);
        }
    }

    public function verify(string $path, ?User $actor = null): array
    {
        $this->authorize($actor, 'backups.view');
        try {
            if (is_link($path) || ! is_dir($path)) {
                throw new RuntimeException;
            }
            $path = rtrim(realpath($path), '/');
            $all = $this->files($path);
            $manifest = json_decode(file_get_contents($path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $metadata = json_decode(file_get_contents($path.'/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($metadata['format'] ?? null) !== 1 || ($metadata['application'] ?? null) !== 'svu-quality-monitor' || ($metadata['database']['engine'] ?? null) !== 'mysql'
                || ! is_array($metadata['database']['migrations'] ?? null) || ! isset($manifest['database.sql'], $manifest['metadata.json'])) {
                throw new RuntimeException;
            }
            $actual = array_map(fn ($file) => substr($file, strlen($path) + 1), $all);
            $expected = [...array_keys($manifest), 'manifest.json'];
            sort($actual);
            sort($expected);
            if ($actual !== $expected) {
                throw new RuntimeException;
            }
            $size = filesize($path.'/manifest.json');
            foreach ($manifest as $relative => $entry) {
                if (! preg_match('~^(database\.sql|metadata\.json|files/(public|private)/[^\\\\]+)$~D', $relative) || in_array('..', explode('/', $relative), true) || in_array('.env', explode('/', $relative), true)) {
                    throw new RuntimeException;
                }
                $file = $path.'/'.$relative;
                if (filesize($file) !== $entry['size'] || ! hash_equals($entry['sha256'], hash_file('sha256', $file))) {
                    throw new RuntimeException;
                }
                $size += $entry['size'];
            }
            $head = file_get_contents($path.'/database.sql', false, null, 0, 4096);
            if (! str_contains($head, '-- MySQL dump') || $metadata['dump_bytes'] !== filesize($path.'/database.sql')) {
                throw new RuntimeException;
            }
            foreach (['public', 'private'] as $component) {
                if (! is_dir($path.'/files/'.$component)) {
                    throw new RuntimeException;
                }
            }
            if ($metadata['file_count'] !== count($this->files($path.'/files'))) {
                throw new RuntimeException;
            }

            return ['size' => $size, 'metadata' => $metadata];
        } catch (Throwable) {
            $this->audit($actor, 'backup.verification_failed');
            throw new RuntimeException('Backup verification failed.');
        }
    }

    public function restore(string $path, ?User $actor = null): void
    {
        $this->authorize($actor, 'backups.restore');
        $this->locked(function () use ($path, $actor) {
            $verified = $this->verify($path, $actor);
            $current = $this->database->metadata();
            if ($current['migrations'] !== $verified['metadata']['database']['migrations'] || explode('.', $current['version'])[0] !== explode('.', $verified['metadata']['database']['version'])[0]) {
                throw new RuntimeException('Restore requires matching migration set and MySQL major version. Use the matching application image.');
            }
            foreach (config('backup.files') as $key => $target) {
                if (! in_array($key, ['public', 'private'], true) || ! str_ends_with($target, '/'.$key) || in_array(realpath($target), ['/', realpath(base_path()), realpath(storage_path())], true)) {
                    throw new RuntimeException('Unsafe files restore destination.');
                }
                $this->files($target);
            }
            // Stage and verify a private copy before touching the destination.
            $stage = $this->root().'/.restore-'.Str::uuid();
            try {
                $this->copyTree($path, $stage);
                $this->verify($stage);
                $this->createBundle($actor, 'pre-restore');
                if (Artisan::call('down', ['--retry' => 60]) !== 0) {
                    throw new RuntimeException('Cannot enter maintenance mode.');
                }
                $this->audit($actor, 'backup.restore_started');
                try {
                    $this->database->restore($stage.'/database.sql');
                    foreach (config('backup.files') as $key => $target) {
                        if (! str_ends_with($target, '/'.$key) || in_array(realpath($target), ['/', realpath(base_path()), realpath(storage_path())], true)) {
                            throw new RuntimeException('Unsafe files restore destination.');
                        }
                        // Refuse symlink destinations before deleting any local files.
                        $this->files($target);
                        File::ensureDirectoryExists($target, 0700);
                        File::cleanDirectory($target);
                        $this->copyTree($stage.'/files/'.$key, $target);
                        if ($key === 'public') {
                            chmod($target, 0755);
                            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                                chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644);
                            }
                        }
                        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($owner = posix_getpwnam('www-data'))) {
                            chown($target, $owner['uid']);
                            chgrp($target, $owner['gid']);
                            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                                chown($entry->getPathname(), $owner['uid']);
                                chgrp($entry->getPathname(), $owner['gid']);
                            }
                        }
                    }
                    if (Artisan::call('optimize:clear') !== 0) {
                        throw new RuntimeException('Cache clearing failed.');
                    }
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                    if (! User::where('is_active', true)->role('super_admin')->exists()) {
                        throw new RuntimeException;
                    }
                    $this->audit($actor, 'backup.restore_completed');
                    // Operator resumes workers, checks runtime health, then explicitly runs artisan up.
                } catch (Throwable) {
                    $this->audit($actor, 'backup.restore_failed');
                    Log::error('Restore failed; maintenance mode retained.');
                    throw new RuntimeException('Restore failed; maintenance mode retained. Recover using pre-restore backup.');
                }
            } finally {
                File::deleteDirectory($stage);
            }
        });
    }

    public function cleanup(?User $actor = null): array
    {
        $this->authorize($actor, 'backups.create');

        return $this->locked(function () use ($actor) {
            $valid = [];
            foreach (File::directories($this->root()) as $path) {
                if (is_link($path) || ! preg_match('/^backup-\d{8}-\d{6}-[a-f0-9-]{36}$/D', basename($path))) {
                    continue;
                }
                try {
                    $valid[$path] = $this->verify($path)['metadata']['created_at'];
                } catch (Throwable) {
                    continue;
                }
            }
            arsort($valid);
            $removed = [];
            foreach (array_slice(array_keys($valid), max(1, config('backup.keep_last'))) as $path) {
                // Keep recovery checkpoints until an operator explicitly archives/removes them.
                $record = $this->root().'/.runs/'.basename($path).'.json';
                if (! is_file($record) || ! in_array(json_decode(file_get_contents($record), true)['type'] ?? '', ['manual', 'scheduled'], true)) {
                    continue;
                }
                if (File::deleteDirectory($path)) {
                    $removed[] = basename($path);
                }
            }
            $this->audit($actor, 'backup.cleanup');

            return $removed;
        });
    }

    public function health(): array
    {
        $records = [];
        foreach (glob(config('backup.directory').'/.runs/*.json') ?: [] as $path) {
            if (! is_link($path)) {
                $records[] = json_decode(file_get_contents($path), true);
            }
        }
        $records = collect($records)->filter()->sortByDesc('started_at');
        $success = $records->firstWhere('status', 'success');
        $failure = $records->firstWhere('status', 'failed');
        $age = $success ? max(0, Carbon::parse($success['finished_at'])->diffInHours(now(), false)) : null;
        $status = ! $success || ($failure && $failure['started_at'] >= $success['started_at']) || $age > config('backup.down_hours') ? 'down' : ($age > config('backup.warning_hours') ? 'warning' : 'healthy');

        return compact('success', 'failure', 'age', 'status');
    }

    private function authorize(?User $actor, string $permission): void
    {
        if ($actor) {
            abort_unless($actor->is_active && $actor->can($permission) && ($permission !== 'backups.restore' || $actor->hasRole('super_admin')), 403);
        } elseif (! app()->runningInConsole()) {
            abort(403);
        }
    }

    private function audit(?User $actor, string $event): void
    {
        if ($actor) {
            app(AuditLogger::class)->log($event, null, $event, actor: $actor);
        }
    }

    private function root(): string
    {
        $root = config('backup.directory');
        if (! str_starts_with($root, '/') || is_link($root)) {
            throw new RuntimeException('Invalid backup directory.');
        }
        File::ensureDirectoryExists($root, 0700);
        $root = realpath($root);
        if (in_array($root, ['/', realpath(base_path()), realpath(storage_path()), realpath(sys_get_temp_dir())], true)) {
            throw new RuntimeException('Backup requires a dedicated directory.');
        }
        foreach ([public_path(), storage_path('app'), ...array_values(config('backup.files'))] as $forbidden) {
            if ($root === realpath($forbidden) || str_starts_with($root.'/', (realpath($forbidden) ?: $forbidden).'/')) {
                throw new RuntimeException('Backup directory must be outside public and application files.');
            }
        }
        chmod($root, 0700);
        $this->runtimeOwner($root);

        return $root;
    }

    private function locked(callable $callback): mixed
    {
        $lock = fopen($this->root().'/.lock', 'c');
        chmod($this->root().'/.lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another backup operation is running.');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function files(string $root): array
    {
        if (is_link($root)) {
            throw new RuntimeException('Symbolic links are not supported.');
        }
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink() || (! $file->isFile() && ! $file->isDir()) || str_starts_with($file->getFilename(), '.env')) {
                throw new RuntimeException('Unsupported file in backup allowlist.');
            }
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function copyTree(string $source, string $destination): void
    {
        File::ensureDirectoryExists($destination, 0700);
        $files = $this->files($source);
        if (is_dir($source)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry->isDir()) {
                    File::ensureDirectoryExists($destination.'/'.substr($entry->getPathname(), strlen(rtrim($source, '/')) + 1), 0700);
                }
            }
        }
        foreach ($files as $file) {
            $target = $destination.'/'.substr($file, strlen(rtrim($source, '/')) + 1);
            File::ensureDirectoryExists(dirname($target), 0700);
            if (! copy($file, $target)) {
                throw new RuntimeException('File copy failed.');
            }
            chmod($target, 0600);
        }
    }

    private function record(array $record): void
    {
        File::ensureDirectoryExists($this->root().'/.runs', 0700);
        $this->runtimeOwner($this->root().'/.runs');
        $this->json($this->root().'/.runs/'.$record['name'].'.json', $record);
    }

    private function json(string $path, array $data): void
    {
        $temp = $path.'.tmp';
        file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($temp, 0600);
        $this->runtimeOwner($temp);
        rename($temp, $path);
    }

    private function runtimeOwner(string $path): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($owner = posix_getpwnam('www-data'))) {
            chown($path, $owner['uid']);
            chgrp($path, $owner['gid']);
        }
    }
}
