<?php

use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || DB::connection()->getDatabaseName() !== 'backup_drill' || DB::table('information_schema.tables')->where('table_schema', 'backup_drill')->count() !== 0) {
    throw new RuntimeException('Drill requires a new empty isolated backup_drill schema.');
}
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
$actor = User::create(['name' => 'Original fixture', 'email' => 'recovery@example.test', 'password' => bin2hex(random_bytes(24)), 'is_active' => true]);
$actor->assignRole('super_admin');
File::ensureDirectoryExists(storage_path('app/public'));
File::ensureDirectoryExists(storage_path('app/private'));
file_put_contents(storage_path('app/public/logo.txt'), 'original logo');
file_put_contents(storage_path('app/private/fixture.txt'), 'original private file');
$service = app(BackupService::class);
$result = $service->create($actor);
$actor->update(['name' => 'Modified fixture']);
file_put_contents(storage_path('app/public/logo.txt'), 'changed');
file_put_contents(storage_path('app/public/extra.txt'), 'new file');
$restoreExit = Artisan::call('backup:restore', ['path' => $result['path'], '--force' => true, '--workers-stopped' => true, '--actor-email' => $actor->email]);
$checks = ['mysql' => DB::selectOne('select version() as v')->v,
    'restore_command_succeeded' => $restoreExit === 0,
    'database_restored' => User::findOrFail($actor->id)->name === 'Original fixture',
    'public_file_restored' => file_get_contents(storage_path('app/public/logo.txt')) === 'original logo',
    'private_file_restored' => file_get_contents(storage_path('app/private/fixture.txt')) === 'original private file',
    'extra_file_removed' => ! is_file(storage_path('app/public/extra.txt')),
    'maintenance_retained' => app()->isDownForMaintenance(),
    'super_admin_retained' => User::findOrFail($actor->id)->hasRole('super_admin'),
    'pre_restore_created' => collect(glob(config('backup.directory').'/.runs/*.json'))->contains(fn ($p) => (json_decode(file_get_contents($p), true)['type'] ?? '') === 'pre-restore'),
    'verified' => $service->verify($result['path'])['size'] > 0,
    'static_smoke_passed' => Artisan::call('system:smoke-check', ['--static' => true]) === 0];
echo json_encode($checks, JSON_PRETTY_PRINT).PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
