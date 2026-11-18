<?php

use App\Models\BaselinePreparationRun;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\MonitoringOpportunity;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('BENCHMARK_FIXTURE_ENABLED') !== '1'
    || DB::connection()->getDatabaseName() !== 'svu_remediation_workload') {
    fwrite(STDERR, "Refusing to create synthetic workload without BENCHMARK_FIXTURE_ENABLED=1.\n");
    exit(2);
}

$mode = $argv[1] ?? 'report';
$prefix = 'REMEDIATION-BENCHMARK-20261118-';
$serviceIds = fn () => MonitoredService::query()->where('name', 'like', $prefix.'%')->pluck('id');
$cleanup = function () use ($serviceIds, $prefix): void {
    if (DB::table('jobs')->exists() || BaselinePreparationRun::query()->whereIn('status', ['pending', 'processing'])->exists()) {
        fwrite(STDERR, "Drain all isolated queued work before fixture cleanup; pending work is retained.\n");
        exit(2);
    }
    $ids = $serviceIds();
    $baselineIds = ControlChartBaseline::query()->whereIn('monitored_service_id', $ids)->pluck('id');
    DB::table('control_chart_baseline_samples')->whereIn('control_chart_baseline_id', $baselineIds)->delete();
    // Production builders intentionally prohibit research-history deletion.
    // This guarded benchmark owns only its prefixed synthetic rows, so clean
    // those fixtures directly after their samples have been removed.
    DB::table('control_chart_baselines')->whereIn('id', $baselineIds)->delete();
    BaselinePreparationRun::query()->whereIn('monitored_service_id', $ids)->delete();
    MonitoredService::query()->where('name', 'like', $prefix.'%')->delete();
};

if ($mode === 'initialize') {
    // Isolated fixture bootstrap only; never invoked by deployment tooling.
    app(RolesAndPermissionsSeeder::class)->run();
    $user = User::firstOrCreate(['email' => 'remediation@example.invalid'], [
        'name' => 'Isolated reviewer', 'password' => Hash::make(getenv('SVU_BROWSER_PASSWORD') ?: bin2hex(random_bytes(32))), 'is_active' => true,
    ]);
    $user->assignRole('super_admin');
    echo json_encode(['initialized' => true]).PHP_EOL;
    exit;
}

if ($mode === 'cleanup') {
    $cleanup();
    Cache::forget('remediation-benchmark-dispatched-at');
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'seed') {
    $cleanup();
    $started = hrtime(true);
    $end = now('Asia/Damascus')->startOfDay()->utc();
    $start = $end->copy()->subDays(28);
    $serviceCount = max(1, min(100, (int) (getenv('BENCHMARK_SERVICE_COUNT') ?: 20)));
    $stepMinutes = max(1, (int) (getenv('BENCHMARK_STEP_MINUTES') ?: 5));
    $observations = (int) (28 * 24 * 60 / $stepMinutes);
    for ($serviceNumber = 1; $serviceNumber <= $serviceCount; $serviceNumber++) {
        $service = MonitoredService::query()->create([
            'name' => $prefix.str_pad((string) $serviceNumber, 3, '0', STR_PAD_LEFT),
            'url' => 'http://web/',
            'check_type' => 'http',
            'is_active' => true,
            'check_interval_minutes' => $stepMinutes,
            'timeout_seconds' => 10,
            'expected_status_code' => 200,
            'warning_response_ms' => 1000,
            'critical_response_ms' => 3000,
        ]);
        $service->forceFill(['created_at' => $start->copy()->subDay()])->save();
        $rows = [];
        for ($hour = 0; $hour < $observations; $hour++) {
            $checkedAt = $start->copy()->addMinutes($hour * $stepMinutes);
            $success = ($hour + $serviceNumber) % 97 !== 0;
            $rows[] = [
                'monitored_service_id' => $service->id,
                'checked_at' => $checkedAt,
                'source' => ServiceCheck::SOURCE_AUTOMATIC,
                'is_during_maintenance' => false,
                'check_type' => 'http',
                'status_code' => $success ? 200 : 503,
                'response_time_ms' => $success ? 80 + (($hour + $serviceNumber) % 200) : null,
                'is_success' => $success,
                'is_slow' => false,
                'performance_status' => $success ? 'healthy' : 'critical',
                'expected_keyword_found' => null,
                'metadata' => null,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('service_checks')->insert($chunk);
        }
    }
    echo json_encode([
        'services' => $serviceCount,
        'historical_checks' => $serviceCount * $observations,
        'seed_seconds' => round((hrtime(true) - $started) / 1e9, 3),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'outage') {
    $clock = now()->copy();
    $results = [];
    for ($day = 14; $day >= 0; $day--) {
        Carbon::setTestNow($clock->copy()->subDays($day));
        // Expire all uniqueness locks in this isolated fixture database.
        DB::table('cache_locks')->update(['expiration' => now()->subHour()->timestamp]);
        $results[] = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
    }
    Carbon::setTestNow();
    echo json_encode(['daily_runs' => $results, 'depth' => DB::table('jobs')->where('queue', 'baseline-preparation')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

if ($mode === 'dispatch') {
    $queues = $argv[2] ?? 'both';
    $normalStart = hrtime(true);
    $dispatchAt = now()->toIso8601String();
    if ($queues !== 'baseline') {
        Cache::forever('remediation-benchmark-dispatched-at', $dispatchAt);
    }
    if ($queues !== 'baseline' && Artisan::call('services:check-due') !== 0) {
        throw new RuntimeException('Monitoring dispatch failed.');
    }
    $normalDispatch = (hrtime(true) - $normalStart) / 1e6;
    $baselineStart = hrtime(true);
    if ($queues !== 'normal' && Artisan::call('baselines:prepare-due') !== 0) {
        throw new RuntimeException('Baseline dispatch failed.');
    }
    $baselineDispatch = (hrtime(true) - $baselineStart) / 1e6;

    echo json_encode([
        'normal_dispatch_ms' => round($normalDispatch, 3),
        'baseline_dispatch_ms' => round($baselineDispatch, 3),
        'normal_depth' => DB::table('jobs')->where('queue', 'default')->count(),
        'baseline_depth' => DB::table('jobs')->where('queue', 'baseline-preparation')->count(),
        'baseline_command' => $queues === 'normal' ? null : trim(Artisan::output()),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$ids = $serviceIds();
$dispatchedAt = Cache::get('remediation-benchmark-dispatched-at');
$runs = BaselinePreparationRun::query()->whereIn('monitored_service_id', $ids)->get();
$durations = $runs->filter(fn ($run) => $run->started_at && $run->completed_at)
    ->map(fn ($run) => $run->started_at->diffInMilliseconds($run->completed_at));
$latestAutomatic = ServiceCheck::query()
    ->whereIn('monitored_service_id', $ids)
    ->where('source', ServiceCheck::SOURCE_AUTOMATIC)
    ->where('created_at', '>=', $dispatchedAt ?? now()->addDay())
    ->get();

echo json_encode([
    'normal_depth' => DB::table('jobs')->where('queue', 'default')->count(),
    'baseline_depth' => DB::table('jobs')->where('queue', 'baseline-preparation')->count(),
    'failed_jobs' => DB::table('failed_jobs')->whereIn('queue', ['default', 'baseline-preparation'])->count(),
    'normal_checks_completed' => $latestAutomatic->count(),
    'normal_execution_max_ms' => $latestAutomatic->max(fn ($check) => $check->checked_at->diffInMilliseconds($check->created_at)),
    'baseline_runs' => $runs->count(),
    'baseline_completed' => $runs->where('status', 'completed')->count(),
    'services_without_a_new_check' => $ids->diff($latestAutomatic->pluck('monitored_service_id'))->count(),
    'normal_http_latency_mean_ms' => $latestAutomatic->avg('response_time_ms'),
    'normal_http_latency_max_ms' => $latestAutomatic->max('response_time_ms'),
    'baseline_duration_mean_ms' => $durations->isEmpty() ? null : round($durations->average(), 3),
    'baseline_duration_max_ms' => $durations->max(),
    'draft_baselines' => ControlChartBaseline::query()->whereIn('monitored_service_id', $ids)->where('status', 'draft')->count(),
    'approved_baselines' => ControlChartBaseline::query()->whereIn('monitored_service_id', $ids)->where('status', 'approved')->count(),
    'active_baselines' => ControlChartBaseline::query()->whereIn('monitored_service_id', $ids)->whereNotNull('active_key')->count(),
    'fixed_cadence_enabled' => config('monitoring.fixed_cadence.enabled'),
    'slo_auto_preparation_enabled' => config('monitoring.slo.automatic_preparation_enabled'),
    'baseline_health' => app(SystemHealthService::class)->baselineQueueSnapshot(),
    'monitoring_opportunities' => MonitoringOpportunity::query()->whereIn('monitored_service_id', $ids)->count(),
], JSON_THROW_ON_ERROR).PHP_EOL;
