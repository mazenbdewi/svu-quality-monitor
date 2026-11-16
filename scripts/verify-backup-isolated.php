<?php

use App\Models\MonitoredService;
use App\Models\SloProposal;
use App\Models\User;
use App\Services\BackupService;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || config('database.connections.mysql.host') !== 'db'
    || DB::connection()->getDatabaseName() !== 'backup_drill'
    || DB::table('information_schema.tables')->where('table_schema', 'backup_drill')->count() !== 0
    || DB::table('information_schema.schemata')->where('schema_name', 'backup_drill_restore')->exists()) {
    throw new RuntimeException('Drill requires the isolated Docker database and a new empty source and restore schema.');
}
Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
$actor = User::create(['name' => 'Original fixture', 'email' => 'recovery@example.test', 'password' => bin2hex(random_bytes(24)), 'is_active' => true]);
$actor->assignRole('super_admin');
$monitored = MonitoredService::create([
    'name' => 'isolated-backup-synthetic-http', 'url' => 'https://backup.invalid/health', 'check_type' => 'http',
    'check_interval_minutes' => 1440, 'is_active' => true,
    'warning_response_ms' => 2500, 'critical_response_ms' => 5000,
]);
$monitored->forceFill(['created_at' => Carbon::parse('2026-10-01 00:00:00', 'UTC')])->save();
for ($day = 0; $day < 28; $day++) {
    $at = Carbon::parse('2026-10-19 00:00:00', 'UTC')->addDays($day);
    $monitored->serviceChecks()->create([
        'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http',
        'is_during_maintenance' => false, 'is_success' => true,
        'response_time_ms' => 100 + $day, 'status_code' => 200,
    ]);
}
$proposal = app(SloProposalService::class)->prepare($monitored, $actor);
SloProposalService::assertIntegrity($proposal->fresh());
$sourceCounts = ['services' => DB::table('monitored_services')->count(),
    'checks' => DB::table('service_checks')->count(), 'proposals' => DB::table('slo_proposals')->count()];
$sourceDigest = $proposal->payload_digest;
File::ensureDirectoryExists(storage_path('app/public'));
File::ensureDirectoryExists(storage_path('app/private'));
file_put_contents(storage_path('app/public/logo.txt'), 'original logo');
file_put_contents(storage_path('app/private/fixture.txt'), 'original private file');
$service = app(BackupService::class);
$backupStarted = microtime(true);
$result = $service->create($actor);
$backupSeconds = round(microtime(true) - $backupStarted, 3);
$verifyStarted = microtime(true);
$verified = $service->verify($result['path']);
$verifySeconds = round(microtime(true) - $verifyStarted, 3);
$sourceConnection = config('database.connections.mysql');
DB::statement('CREATE DATABASE backup_drill_restore');
config()->set('database.connections.backup_source', $sourceConnection);
config()->set('database.connections.mysql.database', 'backup_drill_restore');
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
$restoreActor = User::create(['name' => 'Destination fixture', 'email' => $actor->email,
    'password' => bin2hex(random_bytes(24)), 'is_active' => true]);
$restoreActor->assignRole('super_admin');
file_put_contents(storage_path('app/public/logo.txt'), 'changed');
file_put_contents(storage_path('app/public/extra.txt'), 'new file');
$restoreStarted = microtime(true);
$restoreExit = Artisan::call('backup:restore', ['path' => $result['path'], '--force' => true, '--workers-stopped' => true, '--actor-email' => $restoreActor->email]);
$restoreSeconds = round(microtime(true) - $restoreStarted, 3);
$restoredProposal = SloProposal::findOrFail($proposal->id);
SloProposalService::assertIntegrity($restoredProposal);
$checks = ['mysql' => DB::selectOne('select version() as v')->v,
    'source_database' => 'backup_drill', 'restore_database' => DB::connection()->getDatabaseName(),
    'source_counts' => $sourceCounts,
    'restored_counts' => ['services' => DB::table('monitored_services')->count(),
        'checks' => DB::table('service_checks')->count(), 'proposals' => DB::table('slo_proposals')->count()],
    'source_unchanged' => DB::connection('backup_source')->table('service_checks')->count() === $sourceCounts['checks'],
    'counts_restored' => $sourceCounts === ['services' => DB::table('monitored_services')->count(),
        'checks' => DB::table('service_checks')->count(), 'proposals' => DB::table('slo_proposals')->count()],
    'proposal_digest_restored' => hash_equals($sourceDigest, $restoredProposal->payload_digest),
    'proposal_integrity_verified' => Artisan::call('slo:audit-frozen') === 0,
    'backup_seconds' => $backupSeconds, 'verify_seconds' => $verifySeconds,
    'restore_seconds' => $restoreSeconds, 'backup_bytes' => $verified['size'],
    'restore_command_succeeded' => $restoreExit === 0,
    'database_restored' => User::findOrFail($actor->id)->name === 'Original fixture',
    'public_file_restored' => file_get_contents(storage_path('app/public/logo.txt')) === 'original logo',
    'private_file_restored' => file_get_contents(storage_path('app/private/fixture.txt')) === 'original private file',
    'extra_file_removed' => ! is_file(storage_path('app/public/extra.txt')),
    'maintenance_retained' => app()->isDownForMaintenance(),
    'super_admin_retained' => User::findOrFail($actor->id)->hasRole('super_admin'),
    'pre_restore_created' => collect(glob(config('backup.directory').'/.runs/*.json'))->contains(fn ($p) => (json_decode(file_get_contents($p), true)['type'] ?? '') === 'pre-restore'),
    'verified' => $verified['size'] > 0,
    'static_smoke_passed' => Artisan::call('system:smoke-check', ['--static' => true]) === 0];
$passed = ! in_array(false, $checks, true);
echo json_encode(['drill_passed' => $passed, ...$checks], JSON_PRETTY_PRINT).PHP_EOL;
exit($passed ? 0 : 1);
