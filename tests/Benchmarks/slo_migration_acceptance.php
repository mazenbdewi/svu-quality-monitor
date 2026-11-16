<?php

/** Disposable-only pre-upgrade SLO migration rehearsal; never target a real database. */

use App\Models\MonitoredService;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'Migration acceptance failed: '.$exception::class.PHP_EOL);
    exit(1);
});

$expectedUuid = getenv('SVU_BENCHMARK_SERVER_UUID');
if (getenv('SVU_MIGRATION_ACCEPTANCE_ALLOW') !== 'yes'
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 33071
    || config('database.connections.mysql.database') !== 'svu_b5c2_upgrade'
    || config('database.connections.mysql.username') !== 'svu_b5b_only'
    || ! is_string($expectedUuid) || ! preg_match('/^[a-f0-9-]{36}$/i', $expectedUuid)) {
    throw new RuntimeException('Refusing migration rehearsal outside the named disposable MySQL schema.');
}
$identity = DB::selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version');
if ($identity->db !== 'svu_b5c2_upgrade' || ! hash_equals(strtolower($expectedUuid), strtolower($identity->uuid))
    || ! str_starts_with($identity->version, '8.4.')
    || DB::table('information_schema.tables')->where('table_schema', 'svu_b5c2_upgrade')->exists()) {
    throw new RuntimeException('The disposable upgrade schema is not empty or the server identity differs.');
}

$first = database_path('migrations/2026_11_16_000001_create_slo_proposals.php');
$second = database_path('migrations/2026_11_16_000002_allow_system_created_slo_proposals.php');
foreach (glob(database_path('migrations/*.php')) as $path) {
    if (in_array($path, [$first, $second], true)) {
        continue;
    }
    if (Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException('A pre-upgrade migration failed.');
    }
}
if (Schema::hasTable('slo_proposals')) {
    throw new RuntimeException('SLO schema exists before its reviewed migrations.');
}
Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
$service = MonitoredService::create([
    'name' => 'b5c2-preupgrade-synthetic-http', 'url' => 'https://upgrade.invalid/health',
    'check_type' => 'http', 'check_interval_minutes' => 1440, 'is_active' => true,
    'warning_response_ms' => 2500, 'critical_response_ms' => 5000,
]);
$service->forceFill(['created_at' => Carbon::parse('2026-10-01 00:00:00', 'UTC')])->save();
$firstCheckAt = Carbon::parse('2026-10-19 00:00:00', 'UTC');
$check = $service->serviceChecks()->make([
    'checked_at' => $firstCheckAt, 'source' => 'automatic',
    'check_type' => 'http', 'is_during_maintenance' => false, 'is_success' => true,
    'response_time_ms' => 123, 'status_code' => 200,
]);
$check->forceFill(['created_at' => $firstCheckAt, 'updated_at' => $firstCheckAt])->save();
$before = [$service->fresh()->toArray(), $check->fresh()->toArray()];
$migrationTimes = [];
foreach ([$first, $second] as $path) {
    $started = microtime(true);
    if (Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException('An SLO migration failed.');
    }
    $migrationTimes[basename($path)] = round(microtime(true) - $started, 3);
}
$preserved = $before === [$service->fresh()->toArray(), $check->fresh()->toArray()];
$createdByNullable = DB::table('information_schema.columns')->where('table_schema', 'svu_b5c2_upgrade')
    ->where('table_name', 'slo_proposals')->where('column_name', 'created_by')->value('is_nullable') === 'YES';
$uniqueIdentity = DB::table('information_schema.statistics')->where('table_schema', 'svu_b5c2_upgrade')
    ->where('table_name', 'slo_proposals')->where('column_name', 'identity_digest')->where('non_unique', 0)->exists();
$serviceIndex = DB::table('information_schema.statistics')->where('table_schema', 'svu_b5c2_upgrade')
    ->where('table_name', 'slo_proposals')->where('index_name', 'slo_proposals_monitored_service_id_status_index')->exists();
$foreignKey = DB::table('information_schema.referential_constraints')->where('constraint_schema', 'svu_b5c2_upgrade')
    ->where('table_name', 'slo_proposals')->where('delete_rule', 'RESTRICT')->exists();
$rerunStarted = microtime(true);
$rerunSafe = Artisan::call('migrate', ['--path' => $first, '--realpath' => true, '--force' => true]) === 0
    && Artisan::call('migrate', ['--path' => $second, '--realpath' => true, '--force' => true]) === 0;
$rerunSeconds = round(microtime(true) - $rerunStarted, 3);

for ($day = 1; $day < 28; $day++) {
    $at = $firstCheckAt->copy()->addDays($day);
    $historical = $service->serviceChecks()->make([
        'checked_at' => $at,
        'source' => 'automatic', 'check_type' => 'http', 'is_during_maintenance' => false,
        'is_success' => true, 'response_time_ms' => 123 + $day, 'status_code' => 200,
    ]);
    $historical->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
}
config()->set('monitoring.slo.automatic_preparation_enabled', true);
$proposal = app(SloProposalService::class)->prepareAutomatically($service);
if (! $proposal) {
    throw new RuntimeException('Synthetic system proposal was not prepared.');
}
SloProposalService::assertIntegrity($proposal->fresh());
$rollbackGuards = [];
foreach ([$second, $first] as $path) {
    $migration = require $path;
    try {
        $migration->down();
        $rollbackGuards[basename($path)] = false;
    } catch (LogicException) {
        $rollbackGuards[basename($path)] = true;
    }
}
$passed = $preserved && $createdByNullable && $uniqueIdentity && $serviceIndex && $foreignKey && $rerunSafe
    && ! in_array(false, $rollbackGuards, true) && $proposal->created_by === null
    && DB::table('slo_proposals')->count() === 1;
echo json_encode(['migration_acceptance_passed' => $passed, 'mysql' => $identity->version,
    'prior_check_latency_ms' => $check->response_time_ms, 'existing_rows_preserved' => $preserved,
    'migration_seconds' => $migrationTimes, 'rerun_seconds' => $rerunSeconds, 'rerun_safe' => $rerunSafe,
    'created_by_nullable' => $createdByNullable, 'unique_identity' => $uniqueIdentity,
    'service_status_index' => $serviceIndex, 'service_fk_restrict' => $foreignKey,
    'system_created_attribution_null' => $proposal->created_by === null,
    'rollback_guards' => $rollbackGuards, 'retained_proposals' => DB::table('slo_proposals')->count()],
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit($passed ? 0 : 1);
