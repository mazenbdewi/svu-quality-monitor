<?php

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\BackupDatabase;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.mysql.host') !== 'closure-db'
    || DB::connection()->getDatabaseName() !== 'closure_drill'
    || DB::table('information_schema.tables')->where('table_schema', 'closure_drill')->exists()) {
    throw new RuntimeException('Requires empty isolated closure_drill database on closure-db.');
}
$backup = app(BackupService::class);
$verified = $backup->verify('/source-bundle');
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
$actor = User::create(['name' => 'Isolated drill operator', 'email' => 'isolated-drill@example.test', 'password' => bin2hex(random_bytes(24)), 'is_active' => true]);
$actor->assignRole('super_admin');
$exit = Artisan::call('backup:restore', ['path' => '/source-bundle', '--force' => true, '--workers-stopped' => true, '--actor-email' => $actor->email]);
$checks = ['restore_command' => $exit === 0, 'maintenance_retained' => app()->isDownForMaintenance(),
    'active_super_admin' => User::where('is_active', true)->role('super_admin')->exists()];

// Independent raw import in a second TEMPORARY schema verifies restored rows.
DB::statement('CREATE DATABASE closure_expected CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.closure_expected' => [...config('database.connections.mysql'), 'database' => 'closure_expected']]);
DB::setDefaultConnection('closure_expected');
try {
    app(BackupDatabase::class)->restore('/source-bundle/database.sql');
} finally {
    DB::setDefaultConnection('mysql');
}
$tables = DB::table('information_schema.tables')->where('table_schema', 'closure_expected')->pluck('TABLE_NAME');
$counts = [];
foreach ($tables as $table) {
    $expected = DB::connection('closure_expected')->table($table)->get();
    $query = DB::table($table);
    // Restore appends one audit event; existing audit records must be preserved.
    if ($table === 'audit_logs') {
        $query->where('id', '<=', $expected->max('id') ?? 0);
    }
    $actual = $query->get();
    $canonical = fn ($rows) => $rows->map(function ($row) {
        $values = (array) $row;
        ksort($values);

        return json_encode($values);
    })->sort()->values()->all();
    $checks['table_'.$table] = $canonical($expected) === $canonical($actual);
    $counts[$table] = $actual->count();
}
foreach (['public', 'private'] as $area) {
    $source = '/source-bundle/files/'.$area;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $target = storage_path('app/'.$area.'/'.substr($file->getPathname(), strlen($source) + 1));
            $checks['persistent_files'] = ($checks['persistent_files'] ?? true) && is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $file->getPathname());
        }
    }
}
$restorePass = ! in_array(false, $checks, true);
echo json_encode(['restore' => $restorePass ? 'PASS' : 'FAIL', 'checks' => $checks, 'row_counts' => $counts, 'bundle_bytes' => $verified['size']], JSON_PRETTY_PRINT).PHP_EOL;
if (! $restorePass) {
    exit(1);
}

// No production service or external endpoint is contacted by this fixture.
$process = new Process([PHP_BINARY, '-S', '127.0.0.1:18731', 'closure-endpoint.php']);
file_put_contents('/tmp/closure-monitor-status', '200');
$process->start();
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 18731, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }
    Artisan::call('up'); // Isolated application only; sync jobs must leave maintenance.
    $service = MonitoredService::create(['name' => 'Isolated HTTP acceptance', 'url' => 'http://127.0.0.1:18731', 'check_type' => 'http', 'is_active' => true, 'notifications_enabled' => false, 'expected_status_code' => 200, 'check_interval_minutes' => 1, 'warning_response_ms' => 10000, 'critical_response_ms' => 20000, 'failure_confirmation_count' => 2, 'recovery_confirmation_count' => 2]);
    $states = [];
    foreach ([200, 503, 503, 200, 200] as $status) {
        file_put_contents('/tmp/closure-monitor-status', (string) $status);
        CheckMonitoredServiceJob::dispatchSync($service->id);
        $states[] = $service->fresh()->operational_state;
    }
    $incident = $service->serviceIncidents()->first();
    $pass = $states === ['healthy', 'pending_failure', 'down', 'recovering', 'healthy']
        && $service->serviceIncidents()->count() === 1 && $incident?->status === 'closed'
        && $incident?->confirmed_at !== null && $incident?->resolved_at !== null;
    echo json_encode(['monitoring' => $pass ? 'PASS' : 'FAIL', 'states' => $states, 'incident_created' => $incident !== null, 'incident_resolved' => $incident?->status === 'closed', 'external_requests' => 0]).PHP_EOL;
    $monitorExit = $pass ? 0 : 1;
} finally {
    $process->stop();
}
exit($monitorExit);
