<?php

/**
 * Disposable-only SLO benchmark. Example:
 * SVU_BENCHMARK_ALLOW=yes DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33071 \
 * DB_DATABASE=svu_b5b_disposable DB_USERNAME=svu_b5b_only DB_PASSWORD=... \
 * SVU_BENCHMARK_SERVER_UUID=... SVU_BENCHMARK_GUARD_TOKEN=... \
 * CACHE_STORE=database QUEUE_CONNECTION=database SLO_AUTO_PREPARATION_ENABLED=true \
 * php tests/Benchmarks/slo_performance.php seed 40000
 */

use App\Models\MonitoredService;
use App\Models\SloProposal;
use App\Models\User;
use App\Services\LatencyPercentileAnalysis;
use App\Services\SliIndicatorAnalysis;
use App\Services\SloCalibrationAnalysis;
use App\Services\SloEvaluationAnalysis;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// A dedicated database user, server identity, and manually provisioned marker
// must agree before any fixture deletion, dispatch, or other database write.
$guardToken = getenv('SVU_BENCHMARK_GUARD_TOKEN');
$expectedServerUuid = getenv('SVU_BENCHMARK_SERVER_UUID');
if (getenv('SVU_BENCHMARK_ALLOW') !== 'yes'
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'svu_b5b_disposable'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 33071
    || config('database.connections.mysql.username') !== 'svu_b5b_only'
    || ! is_string($guardToken) || ! preg_match('/^[a-f0-9]{48}$/', $guardToken)
    || ! is_string($expectedServerUuid) || ! preg_match('/^[a-f0-9-]{36}$/i', $expectedServerUuid)) {
    fwrite(STDERR, "Refusing to run without the dedicated disposable MySQL identity and guard token.\n");
    exit(2);
}
try {
    $identity = DB::selectOne('SELECT DATABASE() AS db, @@server_uuid AS server_uuid');
    $marker = DB::table('svu_benchmark_guard')->where('id', 1)->value('token');
    $valid = $identity->db === 'svu_b5b_disposable'
        && hash_equals(strtolower($expectedServerUuid), strtolower($identity->server_uuid))
        && is_string($marker) && hash_equals($guardToken, $marker)
        && str_starts_with((string) DB::getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), '8.4.');
} catch (Throwable) {
    $valid = false;
}
if (! $valid) {
    fwrite(STDERR, "Refusing to run: disposable MySQL server identity or database marker did not match.\n");
    exit(2);
}

ini_set('memory_limit', '256M');
Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
$mode = $argv[1] ?? '';
$target = (int) ($argv[2] ?? 0);
$startUnix = Carbon::parse('2026-10-18 21:00:00', 'UTC')->timestamp;
$windowSeconds = 28 * 86400;
$assertHostResources = static function (): void {
    $diskBytes = disk_free_space('/tmp');
    $memoryInfo = file_get_contents('/proc/meminfo');
    if ($diskBytes === false || $diskBytes < 8 * 1024 ** 3
        || $memoryInfo === false || ! preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memoryInfo, $matches)
        || (int) $matches[1] < 1536 * 1024) {
        throw new RuntimeException('Benchmark stopped: require at least 8 GiB free disk and 1.5 GiB host available RAM.');
    }
};

if ($mode === 'seed') {
    if (! in_array($target, [40000, 100000, 250000, 500000], true)) {
        throw new InvalidArgumentException('Expected one of 40000, 100000, 250000, 500000 checks.');
    }
    $assertHostResources();
    // The dedicated user, server UUID, marker, connection and opt-in were checked above.
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    DB::table('audit_logs')->delete();
    DB::table('slo_proposals')->delete();
    DB::table('service_checks')->delete();
    DB::table('monitored_services')->delete();
    $queueChecks = 62 * 28; // Three queue cohorts plus a separate lifecycle/evaluation fixture.
    $bulkChecks = $target - $queueChecks;
    $weights = array_map(fn (int $i): int => 1 + (($i * 7) % 13), range(1, 50));
    $weightSum = array_sum($weights);
    $assigned = 0;
    $counts = [];
    $inserted = 0;
    $wallStart = microtime(true);

    for ($serviceNumber = 1; $serviceNumber <= 50; $serviceNumber++) {
        $count = $serviceNumber === 50 ? $bulkChecks - $assigned
            : (int) floor($bulkChecks * $weights[$serviceNumber - 1] / $weightSum);
        $assigned += $count;
        $counts[] = $count;
        $type = $serviceNumber % 3 === 0 ? 'api' : 'http';
        $interval = $count > 16000 ? 1 : ($count > 7000 ? 5 : ($count > 2500 ? 15 : ($count > 1000 ? 30 : 60)));
        $serviceId = DB::table('monitored_services')->insertGetId([
            'name' => sprintf('b5b-bulk-%02d', $serviceNumber),
            'url' => sprintf('https://benchmark-%02d.invalid/health', $serviceNumber),
            'check_type' => $type, 'check_interval_minutes' => $interval,
            'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
            'is_active' => false, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);
        $batch = [];
        for ($index = 0; $index < $count; $index++) {
            $value = ($index * 1103515245 + $serviceNumber * 12345 + 20261116) & 0x7FFFFFFF;
            $bucket = $value % 1000;
            $manual = $bucket < 20;
            $maintenance = $bucket >= 20 && $bucket < 30;
            $failed = $bucket >= 30 && $bucket < 65;
            $missingLatency = $bucket >= 65 && $bucket < 75;
            $slow = (($value >> 10) % 100) < 5;
            $latency = $missingLatency ? null : 80 + $serviceNumber * 11 + ($value % 850) + ($slow ? 3000 + ($value % 7000) : 0);
            $checkedAt = gmdate('Y-m-d H:i:s', $startUnix + (int) floor(($index + .5) * $windowSeconds / $count));
            $batch[] = [
                'monitored_service_id' => $serviceId, 'checked_at' => $checkedAt,
                'source' => $manual ? 'manual' : 'automatic', 'check_type' => $type,
                'is_during_maintenance' => $maintenance, 'is_success' => ! $failed,
                'is_slow' => $slow && ! $failed, 'response_time_ms' => $latency,
                'status_code' => $failed ? 503 : 200, 'created_at' => $checkedAt, 'updated_at' => $checkedAt,
            ];
            if (count($batch) === 500) {
                DB::table('service_checks')->insert($batch);
                $inserted += count($batch);
                $batch = [];
                if ($inserted % 25000 === 0) {
                    $assertHostResources();
                }
            }
        }
        if ($batch !== []) {
            DB::table('service_checks')->insert($batch);
            $inserted += count($batch);
        }
    }
    foreach ([1, 10, 50] as $group) {
        for ($serviceNumber = 1; $serviceNumber <= $group; $serviceNumber++) {
            $type = $serviceNumber % 3 === 0 ? 'api' : 'http';
            $serviceId = DB::table('monitored_services')->insertGetId([
                'name' => "b5b-queue-{$group}-{$serviceNumber}",
                'url' => "https://queue-{$group}-{$serviceNumber}.invalid/health",
                'check_type' => $type, 'check_interval_minutes' => 1440,
                'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
                'is_active' => false, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
            ]);
            $rows = [];
            for ($day = 0; $day < 28; $day++) {
                $checkedAt = gmdate('Y-m-d H:i:s', $startUnix + $day * 86400 + 3600);
                $failed = $day === 4 && $serviceNumber % 7 === 0;
                $rows[] = [
                    'monitored_service_id' => $serviceId, 'checked_at' => $checkedAt,
                    'source' => 'automatic', 'check_type' => $type, 'is_during_maintenance' => false,
                    'is_success' => ! $failed, 'is_slow' => false,
                    'response_time_ms' => $failed ? null : 150 + $serviceNumber * 3 + $day,
                    'status_code' => $failed ? 503 : 200,
                    'created_at' => $checkedAt, 'updated_at' => $checkedAt,
                ];
            }
            DB::table('service_checks')->insert($rows);
            $inserted += count($rows);
        }
    }
    $proposalId = DB::table('monitored_services')->insertGetId([
        'name' => 'b5b-proposal-1', 'url' => 'https://proposal.invalid/health',
        'check_type' => 'http', 'check_interval_minutes' => 1440,
        'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
        'is_active' => true, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
    ]);
    $proposalRows = [];
    for ($day = 0; $day < 28; $day++) {
        $checkedAt = gmdate('Y-m-d H:i:s', $startUnix + $day * 86400 + 3600);
        $proposalRows[] = [
            'monitored_service_id' => $proposalId, 'checked_at' => $checkedAt,
            'source' => 'automatic', 'check_type' => 'http', 'is_during_maintenance' => false,
            'is_success' => true, 'is_slow' => false, 'response_time_ms' => 180 + $day,
            'status_code' => 200, 'created_at' => $checkedAt, 'updated_at' => $checkedAt,
        ];
    }
    DB::table('service_checks')->insert($proposalRows);
    $inserted += count($proposalRows);
    echo json_encode([
        'mode' => 'seed', 'target_rows' => $target, 'actual_rows' => $inserted,
        'bulk_services' => 50, 'queue_services' => 61, 'proposal_services' => 1, 'smallest_bulk' => min($counts),
        'largest_bulk' => max($counts), 'seconds' => round(microtime(true) - $wallStart, 3),
        'window_utc' => ['2026-10-18T21:00:00Z', '2026-11-15T21:00:00Z'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'measure') {
    $ids = DB::table('monitored_services')->where('name', 'like', 'b5b-bulk-%')->pluck('id')->all();
    if (count($ids) !== 50) {
        throw new RuntimeException('Seed the disposable workload first.');
    }
    $sizes = DB::table('service_checks')->selectRaw('monitored_service_id, COUNT(*) AS n')
        ->whereIn('monitored_service_id', $ids)->groupBy('monitored_service_id')->orderBy('n')->get();
    $selected = [$sizes->first(), $sizes[(int) floor($sizes->count() / 2)], $sizes->last()];
    $counter = ['queries' => 0, 'sql_ms' => 0.0, 'ranking_sql' => null, 'aggregate_sql' => null];
    DB::listen(function ($query) use (&$counter): void {
        $counter['queries']++;
        $counter['sql_ms'] += $query->time;
        if (str_contains($query->sql, 'ROW_NUMBER()')) {
            $counter['ranking_sql'] = $query->toRawSql();
        } elseif (str_contains($query->sql, 'latency_observations')) {
            $counter['aggregate_sql'] = $query->toRawSql();
        }
    });
    $measure = function (callable $action, int $repetitions = 5) use (&$counter): array {
        $runs = [];
        for ($i = 0; $i < $repetitions; $i++) {
            $counter['queries'] = 0;
            $counter['sql_ms'] = 0.0;
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $before = memory_get_usage(true);
            $start = hrtime(true);
            $value = $action();
            $runs[] = [
                'wall_ms' => round((hrtime(true) - $start) / 1e6, 3),
                'sql_ms' => round($counter['sql_ms'], 3),
                'queries' => $counter['queries'],
                'peak_extra_mb' => round(max(0, memory_get_peak_usage(true) - $before) / 1048576, 3),
                'peak_total_mb' => round(memory_get_peak_usage(true) / 1048576, 3),
            ];
        }
        $times = array_column($runs, 'wall_ms');
        sort($times);
        $last = count($times) - 1;
        $p95Position = .95 * $last;
        $lower = (int) floor($p95Position);
        $upper = (int) ceil($p95Position);

        return [
            'result' => $value, 'runs' => $runs,
            'median_ms' => $times[(int) floor(count($times) / 2)],
            'p95_ms_type7' => round($times[$lower] + ($p95Position - $lower) * ($times[$upper] - $times[$lower]), 3),
        ];
    };
    $output = ['mode' => 'measure', 'table_rows' => DB::table('service_checks')->count(), 'services' => []];
    foreach ($selected as $rank => $entry) {
        $service = MonitoredService::findOrFail($entry->monitored_service_id);
        $latency = $measure(fn () => app(LatencyPercentileAnalysis::class)->analyze($service));
        $sli = $measure(fn () => app(SliIndicatorAnalysis::class)->describe($service,
            Carbon::parse('2026-10-18 21:00:00', 'UTC'), Carbon::parse('2026-11-15 21:00:00', 'UTC'), 2000));
        $calibration = $measure(fn () => app(SloCalibrationAnalysis::class)->propose($service));
        $output['services'][] = [
            'rank' => ['small', 'median', 'large'][$rank], 'service_id' => $service->id,
            'raw_checks' => (int) $entry->n, 'eligible_observations' => $latency['result']['latency_observations'],
            'percentiles_ms' => array_intersect_key($latency['result'], array_flip(['p50_ms', 'p90_ms', 'p95_ms', 'p99_ms'])),
            'latency_benchmark' => array_diff_key($latency, ['result' => true]),
            'sli_benchmark' => array_diff_key($sli, ['result' => true]),
            'calibration_benchmark' => array_diff_key($calibration, ['result' => true]),
            'sli_counts' => array_intersect_key($sli['result'], array_flip(['executed_checks', 'successful_checks', 'successful_with_valid_latency', 'successful_within_objective'])),
            'calibration_status' => $calibration['result']['status'],
            'repeatable' => $latency['result'] === app(LatencyPercentileAnalysis::class)->analyze($service)
                && $calibration['result'] === app(SloCalibrationAnalysis::class)->propose($service),
        ];
        if ($rank === 2) {
            $capturedSql = ['ranking_sql' => $counter['ranking_sql'], 'aggregate_sql' => $counter['aggregate_sql']];
            foreach ($capturedSql as $key => $sql) {
                if ($sql !== null) {
                    $output['plans'][$key] = DB::select('EXPLAIN FORMAT=TREE '.$sql);
                }
            }
            if ($capturedSql['ranking_sql'] !== null) {
                $output['plans']['ranking_actual'] = DB::select('EXPLAIN ANALYZE '.$capturedSql['ranking_sql']);
            }
        }
    }
    $queueService = MonitoredService::query()->where('name', 'b5b-proposal-1')->firstOrFail();
    $proposal = $measure(fn () => app(SloProposalService::class)->prepareAutomatically($queueService), 1);
    $repeat = $measure(fn () => app(SloProposalService::class)->prepareAutomatically($queueService), 1);
    $output['preparation'] = [
        'first' => $proposal['runs'][0], 'repeat' => $repeat['runs'][0],
        'proposal_id' => $proposal['result']?->id, 'repeat_result' => $repeat['result']?->id,
    ];
    if ($proposal['result'] instanceof SloProposal) {
        (new RolesAndPermissionsSeeder)->run();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole('administrator');
        $manager = app(SloProposalService::class);
        $manager->review($proposal['result'], $actor, 'Disposable benchmark limits acknowledged.', true);
        $manager->approve($proposal['result']->fresh(), $actor, 'Synthetic benchmark only.');
        foreach ([17, 18] as $day) {
            $at = "2026-11-{$day} 00:00:00";
            DB::table('service_checks')->insert([
                'monitored_service_id' => $queueService->id, 'checked_at' => $at,
                'source' => 'automatic', 'check_type' => $queueService->check_type->value,
                'is_during_maintenance' => false, 'is_success' => true, 'is_slow' => false,
                'response_time_ms' => 180, 'status_code' => 200,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $evaluation = $measure(fn () => app(SloEvaluationAnalysis::class)->evaluate($proposal['result']->fresh(),
            Carbon::parse('2026-11-17 00:00:00', 'UTC'), Carbon::parse('2026-11-19 00:00:00', 'UTC')));
        $output['evaluation'] = [
            'benchmark' => array_diff_key($evaluation, ['result' => true]),
            'mode' => $evaluation['result']['evaluation_mode'],
            'status' => $evaluation['result']['target_status'],
            'executed_checks' => $evaluation['result']['sli']['executed_checks'],
        ];
    }
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if (in_array($mode, ['queue', 'dispatch'], true)) {
    if (! in_array($target, [1, 10, 50], true)) {
        throw new InvalidArgumentException('Expected queue cohort 1, 10 or 50.');
    }
    DB::table('monitored_services')->where('name', 'b5b-proposal-1')->update(['is_active' => false]);
    DB::table('monitored_services')->where('name', 'like', 'b5b-queue-%')->update(['is_active' => false]);
    DB::table('monitored_services')->where('name', 'like', "b5b-queue-{$target}-%")->update(['is_active' => true]);
    $started = microtime(true);
    Artisan::call('slo:prepare-due');
    $dispatchSeconds = microtime(true) - $started;
    $dispatchOutput = trim(Artisan::output());
    $pending = DB::table('jobs')->count();
    preg_match('/dispatched: (\d+)/', $dispatchOutput, $dispatchMatches);
    $newlyDispatched = (int) ($dispatchMatches[1] ?? 0);
    if ($mode === 'dispatch') {
        echo json_encode([
            'mode' => 'dispatch', 'cohort' => $target,
            'seconds' => round($dispatchSeconds, 3), 'output' => $dispatchOutput,
            'jobs_pending' => $pending,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    $startedAgain = microtime(true);
    Artisan::call('slo:prepare-due');
    $repeatSeconds = microtime(true) - $startedAgain;
    $repeatOutput = trim(Artisan::output());
    $cohortIds = DB::table('monitored_services')->where('name', 'like', "b5b-queue-{$target}-%")
        ->pluck('id')->all();
    $proposalsBefore = DB::table('slo_proposals')->whereIn('monitored_service_id', $cohortIds)->count();
    $draftsBefore = DB::table('slo_proposals')->whereIn('monitored_service_id', $cohortIds)
        ->where('status', 'draft')->count();
    $rankedCalibrationQueries = 0;
    DB::listen(function ($query) use (&$rankedCalibrationQueries): void {
        if (str_contains($query->sql, 'ROW_NUMBER()')) {
            $rankedCalibrationQueries++;
        }
    });
    $processing = [];
    $durations = [];
    $workerStart = microtime(true);
    $waitsLower = [];
    $waitsUpper = [];
    Queue::before(function ($event) use (&$processing, &$waitsLower, &$waitsUpper, &$workerStart, $started): void {
        $id = $event->job->getJobId();
        $processing[$id] = microtime(true);
        // Frozen Carbon time is used for historical windows, so database job
        // timestamps cannot be compared to the real monotonic benchmark clock.
        $waitsLower[] = round(($processing[$id] - $workerStart) * 1000, 3);
        $waitsUpper[] = round(($processing[$id] - $started) * 1000, 3);
    });
    Queue::after(function ($event) use (&$processing, &$durations): void {
        $id = $event->job->getJobId();
        $durations[] = round((microtime(true) - $processing[$id]) * 1000, 3);
    });
    $workerStart = microtime(true);
    Artisan::call('queue:work', [
        'connection' => 'database', '--queue' => 'slo-calibration', '--stop-when-empty' => true, '--sleep' => 0,
        '--tries' => 3, '--timeout' => 60, '--max-time' => 120, '--max-jobs' => $target,
    ]);
    $workerSeconds = microtime(true) - $workerStart;
    sort($durations);
    sort($waitsLower);
    sort($waitsUpper);
    echo json_encode([
        'mode' => 'queue', 'cohort' => $target,
        'dispatch_seconds' => round($dispatchSeconds, 3), 'dispatch_output' => $dispatchOutput,
        'repeat_seconds' => round($repeatSeconds, 3), 'repeat_output' => $repeatOutput,
        'jobs_pending_after_dispatch' => $pending,
        'worker_seconds' => round($workerSeconds, 3), 'processed' => count($durations),
        'ranked_calibration_queries' => $rankedCalibrationQueries,
        'jobs_without_ranked_calibration' => count($durations) - $rankedCalibrationQueries,
        'proposals_before' => $proposalsBefore,
        'new_drafts' => DB::table('slo_proposals')->whereIn('monitored_service_id', $cohortIds)
            ->where('status', 'draft')->count() - $draftsBefore,
        'job_median_ms' => $durations === [] ? null : $durations[(int) floor(count($durations) / 2)],
        'job_max_ms' => $durations === [] ? null : max($durations),
        'queue_wait_median_lower_bound_ms' => $waitsLower === [] ? null : $waitsLower[(int) floor(count($waitsLower) / 2)],
        'queue_wait_median_upper_bound_ms' => $waitsUpper === [] || $pending !== $newlyDispatched
            ? null : $waitsUpper[(int) floor(count($waitsUpper) / 2)],
        'queue_wait_max_lower_bound_ms' => $waitsLower === [] ? null : max($waitsLower),
        'queue_wait_max_upper_bound_ms' => $waitsUpper === [] || $pending !== $newlyDispatched ? null : max($waitsUpper),
        'worker_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 3),
        'jobs_remaining' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'proposals' => DB::table('slo_proposals')->whereIn('monitored_service_id',
            DB::table('monitored_services')->select('id')->where('name', 'like', "b5b-queue-{$target}-%"))->count(),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

// A bounded two-worker smoke: one high-volume calibration and ten local-only
// synthetic HTTP checks. Coordinate worker processes outside this runner.
if ($mode === 'stage-interference') {
    Carbon::setTestNow();
    $assertHostResources();
    $largest = DB::table('service_checks')->selectRaw('monitored_service_id, COUNT(*) AS n')
        ->whereIn('monitored_service_id', DB::table('monitored_services')->select('id')->where('name', 'like', 'b5b-bulk-%'))
        ->groupBy('monitored_service_id')->orderByDesc('n')->first();
    if (! $largest || (int) $largest->n < 16000 || DB::table('jobs')->exists()
        || DB::table('monitored_services')->where('name', 'like', 'b5b-interference-check-%')->exists()) {
        throw new RuntimeException('Expected a freshly seeded 500,000-row disposable fixture and an empty queue.');
    }
    DB::table('monitored_services')->where('name', 'b5b-proposal-1')->update(['is_active' => false]);
    DB::table('monitored_services')->where('name', 'like', 'b5b-queue-%')->update(['is_active' => false]);
    DB::table('monitored_services')->where('id', $largest->monitored_service_id)
        ->update(['is_active' => true, 'check_interval_minutes' => 5]);
    // A recent out-of-window manual check makes the bulk service not due for
    // operational HTTP checking; it cannot enter the historical calibration.
    DB::table('service_checks')->insert([
        'monitored_service_id' => $largest->monitored_service_id, 'checked_at' => now()->utc()->format('Y-m-d H:i:s'),
        'source' => 'manual', 'check_type' => DB::table('monitored_services')->where('id', $largest->monitored_service_id)->value('check_type'),
        'is_during_maintenance' => false,
        'is_success' => true, 'response_time_ms' => 100, 'status_code' => 200,
        'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
    ]);
    for ($i = 1; $i <= 10; $i++) {
        DB::table('monitored_services')->insert([
            'name' => "b5b-interference-check-{$i}", 'url' => 'http://127.0.0.1:18081/up',
            'check_type' => 'http', 'check_interval_minutes' => 1, 'is_active' => true,
            'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }
    echo json_encode(['mode' => $mode, 'bulk_service_id' => $largest->monitored_service_id,
        'bulk_historical_rows' => $largest->n, 'monitoring_services' => 10], JSON_THROW_ON_ERROR)."\n";
    exit;
}

// Two 90-second phases against 50 historical HTTP/API services. Both phases
// run ten local-only one-minute monitors; optional nightly probes execute in
// the parent process. Only the second phase has queued high-volume calibration.
if ($mode === 'stage-sustained') {
    Carbon::setTestNow();
    $assertHostResources();
    if (DB::table('service_checks')->count() !== 500000 || DB::table('jobs')->exists()
        || DB::table('slo_proposals')->exists()
        || DB::table('monitored_services')->where('name', 'like', 'b5c2-%')->exists()
        || Schema::hasTable('b5c2_events')) {
        throw new RuntimeException('Expected a new 500,000-check disposable fixture with empty queues and proposals.');
    }
    DB::table('monitored_services')->update(['is_active' => false]);
    $bulk = DB::table('monitored_services')->where('name', 'like', 'b5b-bulk-%')->orderBy('id')->get(['id', 'check_interval_minutes']);
    if ($bulk->count() !== 50) {
        throw new RuntimeException('Expected exactly 50 historical bulk services.');
    }
    foreach ($bulk as $service) {
        DB::table('monitored_services')->where('id', $service->id)->update([
            'is_active' => true, 'check_interval_minutes' => max(5, $service->check_interval_minutes),
        ]);
        // This current manual marker prevents the historical calibration
        // services from being operationally checked during the 75-second run.
        DB::table('service_checks')->insert([
            'monitored_service_id' => $service->id, 'checked_at' => now()->utc()->format('Y-m-d H:i:s'),
            'source' => 'manual', 'check_type' => DB::table('monitored_services')->where('id', $service->id)->value('check_type'),
            'is_during_maintenance' => false, 'is_success' => true, 'response_time_ms' => 100,
            'status_code' => 200, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }
    foreach (['baseline', 'concurrent'] as $phase) {
        for ($index = 1; $index <= 10; $index++) {
            DB::table('monitored_services')->insert([
                'name' => "b5c2-{$phase}-{$index}", 'url' => 'http://127.0.0.1:18081/up',
                'check_type' => $index % 3 === 0 ? 'api' : 'http',
                'check_interval_minutes' => 1, 'is_active' => false,
                'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
                'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
        }
    }
    Schema::create('b5c2_events', function (Blueprint $table): void {
        $table->id();
        $table->string('phase', 20);
        $table->string('queue', 30);
        $table->unsignedBigInteger('job_id');
        $table->double('due_unix')->nullable();
        $table->double('enqueued_unix');
        $table->double('started_unix');
        $table->double('finished_unix')->nullable();
        $table->boolean('failed')->default(false);
    });
    echo json_encode(['mode' => $mode, 'bulk_services' => $bulk->count(), 'monitoring_services_per_phase' => 10,
        'historical_checks' => 500000, 'manual_operational_markers' => 50], JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'reset-sustained') {
    if (! Schema::hasTable('b5c2_events') || DB::table('jobs')->where('queue', 'slo-calibration')->exists()
        || DB::table('slo_proposals')->exists()) {
        throw new RuntimeException('Reset is allowed only before SLO calibration in the disposable fixture.');
    }
    $monitorIds = DB::table('monitored_services')->where('name', 'like', 'b5c2-%')->pluck('id');
    DB::table('jobs')->where('queue', 'default')->delete();
    DB::table('service_checks')->whereIn('monitored_service_id', $monitorIds)->delete();
    DB::table('b5c2_events')->delete();
    DB::table('monitored_services')->whereIn('id', $monitorIds)->update(['is_active' => false]);
    // Keep the historical bulk cohort eligible for daily SLO calibration but
    // out of the operational one-minute check queue during this experiment.
    DB::table('monitored_services')->where('name', 'like', 'b5b-bulk-%')->update(['check_interval_minutes' => 1440]);
    echo json_encode(['mode' => $mode, 'monitor_services' => $monitorIds->count(),
        'remaining_benchmark_monitor_checks' => DB::table('service_checks')->whereIn('monitored_service_id', $monitorIds)->count()], JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'run-sustained') {
    Carbon::setTestNow();
    $phase = $argv[2] ?? '';
    $withNightly = ($argv[3] ?? '') === 'nightly';
    if (! in_array($phase, ['baseline', 'concurrent'], true) || ! Schema::hasTable('b5c2_events')
        || DB::table('b5c2_events')->where('phase', $phase)->exists()
        || DB::table('jobs')->exists()
        || DB::table('monitored_services')->where('name', 'like', "b5c2-{$phase}-%")->count() !== 10) {
        throw new RuntimeException('Stage a fresh disposable sustained fixture and run each phase only once.');
    }
    $assertHostResources();
    DB::table('monitored_services')->where('name', 'like', 'b5c2-%')->update(['is_active' => false]);
    DB::table('monitored_services')->where('name', 'like', "b5c2-{$phase}-%")->update(['is_active' => true]);
    $benchmarkDue = null;
    Queue::createPayloadUsing(static function () use (&$benchmarkDue): array {
        return ['b5c2_due_unix' => $benchmarkDue, 'b5c2_enqueued_unix' => microtime(true)];
    });
    $phaseStart = microtime(true);
    $dispatches = [];
    $nightly = [];
    $maxDefaultBacklog = 0;
    $maxSloBacklog = 0;
    if ($phase === 'concurrent') {
        $sloDispatchStart = microtime(true);
        Artisan::call('slo:prepare-due');
        $sloDispatchSeconds = microtime(true) - $sloDispatchStart;
        $initialSloJobs = DB::table('jobs')->where('queue', 'slo-calibration')->count();
        if ($initialSloJobs < 20 || $initialSloJobs > 50) {
            throw new RuntimeException('Expected 20-50 real high-volume SLO jobs, got '.$initialSloJobs);
        }
    } else {
        $sloDispatchSeconds = null;
        $initialSloJobs = 0;
    }
    $workers = [];
    foreach ($phase === 'concurrent' ? ['default', 'slo-calibration'] : ['default'] as $queueName) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork a disposable benchmark worker.');
        }
        if ($pid === 0) {
            DB::disconnect();
            $started = [];
            $queryTimes = [];
            DB::listen(static function ($query) use (&$queryTimes): void {
                if (count($queryTimes) < 20000) {
                    $queryTimes[] = $query->time;
                }
            });
            Queue::before(static function ($event) use (&$started, $phase): void {
                $payload = $event->job->payload();
                $started[$event->job->getJobId()] = DB::table('b5c2_events')->insertGetId([
                    'phase' => $phase, 'queue' => $event->job->getQueue(), 'job_id' => $event->job->getJobId(),
                    'due_unix' => $payload['b5c2_due_unix'] ?? null,
                    'enqueued_unix' => $payload['b5c2_enqueued_unix'] ?? microtime(true),
                    'started_unix' => microtime(true),
                ]);
            });
            Queue::after(static function ($event) use (&$started): void {
                DB::table('b5c2_events')->where('id', $started[$event->job->getJobId()])->update(['finished_unix' => microtime(true)]);
            });
            Queue::failing(static function ($event) use (&$started): void {
                if (isset($started[$event->job->getJobId()])) {
                    DB::table('b5c2_events')->where('id', $started[$event->job->getJobId()])->update([
                        'failed' => true, 'finished_unix' => microtime(true),
                    ]);
                }
            });
            $usageBefore = getrusage();
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queueName,
                '--sleep' => 1, '--tries' => 3, '--timeout' => 60, '--max-time' => 125,
                '--max-jobs' => $queueName === 'default' ? 25 : $initialSloJobs]);
            $usageAfter = getrusage();
            sort($queryTimes);
            $p95Index = $queryTimes === [] ? null : (int) ceil(.95 * count($queryTimes)) - 1;
            echo json_encode(['worker' => $queueName, 'phase' => $phase,
                'sql_query_count' => count($queryTimes),
                'sql_query_p95_ms' => $p95Index === null ? null : $queryTimes[$p95Index],
                'cpu_user_seconds' => round(($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'])
                    + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec']) / 1000000, 3),
                'peak_php_mb' => round(memory_get_peak_usage(true) / 1048576, 3)], JSON_THROW_ON_ERROR)."\n";
            exit;
        }
        $workers[$queueName] = $pid;
    }
    // Allow for the first round's actual HTTP execution time before the
    // one-minute check interval elapses; exactly +60s can be too early.
    foreach ([0, 65] as $offset) {
        $target = $phaseStart + $offset;
        while (microtime(true) < $target) {
            $maxDefaultBacklog = max($maxDefaultBacklog, DB::table('jobs')->where('queue', 'default')->count());
            $maxSloBacklog = max($maxSloBacklog, DB::table('jobs')->where('queue', 'slo-calibration')->count());
            usleep(250000);
        }
        $benchmarkDue = $target;
        $started = microtime(true);
        Artisan::call('services:check-due');
        $dispatches[] = ['due_unix' => $target, 'start_delay_seconds' => round($started - $target, 3),
            'elapsed_seconds' => round(microtime(true) - $started, 3)];
        $benchmarkDue = null;
        if ($offset === 0 && $withNightly) {
            foreach (['reliability:calculate --period=daily', 'sla:calculate', 'control-charts:calculate --period=daily', 'spc:evaluate'] as $command) {
                $started = microtime(true);
                Artisan::call($command);
                $nightly[] = ['command' => $command, 'elapsed_seconds' => round(microtime(true) - $started, 3)];
            }
        }
    }
    while (microtime(true) < $phaseStart + 90) {
        $maxDefaultBacklog = max($maxDefaultBacklog, DB::table('jobs')->where('queue', 'default')->count());
        $maxSloBacklog = max($maxSloBacklog, DB::table('jobs')->where('queue', 'slo-calibration')->count());
        usleep(250000);
    }
    posix_kill($workers['default'], SIGTERM);
    foreach ($workers as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $events = DB::table('b5c2_events')->where('phase', $phase)->get();
    $distribution = static function (array $values): array {
        if ($values === []) {
            return ['n' => 0, 'p50' => null, 'p95' => null, 'p99' => null, 'max' => null];
        }
        sort($values);
        $percentile = static function (float $p) use ($values): float {
            $h = $p * (count($values) - 1);
            $lo = (int) floor($h);
            $hi = (int) ceil($h);

            return round($values[$lo] + ($h - $lo) * ($values[$hi] - $values[$lo]), 3);
        };

        return ['n' => count($values), 'p50' => $percentile(.50), 'p95' => $percentile(.95),
            'p99' => $percentile(.99), 'max' => round(max($values), 3)];
    };
    $checks = $events->where('queue', 'default');
    $slos = $events->where('queue', 'slo-calibration');
    echo json_encode(['mode' => $mode, 'phase' => $phase, 'nightly_commands_in_same_process' => $withNightly,
        'duration_seconds' => round(microtime(true) - $phaseStart, 3),
        'slo_dispatch_seconds' => $sloDispatchSeconds, 'initial_slo_jobs' => $initialSloJobs,
        'dispatches' => $dispatches, 'nightly' => $nightly,
        'check_queue_wait_seconds' => $distribution($checks->map(fn ($e) => $e->started_unix - $e->enqueued_unix)->all()),
        'check_end_lateness_seconds' => $distribution($checks->filter(fn ($e) => $e->finished_unix && $e->due_unix)
            ->map(fn ($e) => $e->finished_unix - $e->due_unix)->all()),
        'slo_queue_wait_seconds' => $distribution($slos->map(fn ($e) => $e->started_unix - $e->enqueued_unix)->all()),
        'slo_execution_seconds' => $distribution($slos->filter(fn ($e) => $e->finished_unix)
            ->map(fn ($e) => $e->finished_unix - $e->started_unix)->all()),
        'check_events' => $checks->count(), 'slo_events' => $slos->count(),
        'max_default_backlog' => $maxDefaultBacklog, 'max_slo_backlog' => $maxSloBacklog,
        'pending_default' => DB::table('jobs')->where('queue', 'default')->count(),
        'pending_slo' => DB::table('jobs')->where('queue', 'slo-calibration')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'successful_checks' => DB::table('service_checks')->whereIn('monitored_service_id',
            DB::table('monitored_services')->select('id')->where('name', 'like', "b5c2-{$phase}-%"))->where('is_success', true)->count(),
        'failed_checks' => DB::table('service_checks')->whereIn('monitored_service_id',
            DB::table('monitored_services')->select('id')->where('name', 'like', "b5c2-{$phase}-%"))->where('is_success', false)->count()],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'concurrent-dispatch') {
    Carbon::setTestNow();
    if (DB::table('jobs')->exists() || DB::table('monitored_services')->where('name', 'b5c2-concurrent-scheduler')->exists()) {
        throw new RuntimeException('Expected an empty disposable queue and no prior concurrency fixture.');
    }
    $window = app(LatencyPercentileAnalysis::class)->window();
    $serviceId = DB::table('monitored_services')->insertGetId([
        'name' => 'b5c2-concurrent-scheduler', 'url' => 'https://concurrency.invalid/health',
        'check_type' => 'http', 'check_interval_minutes' => 1440, 'is_active' => true,
        'warning_response_ms' => 2500, 'critical_response_ms' => 5000,
        'created_at' => $window['start']->copy()->subDay()->format('Y-m-d H:i:s'),
        'updated_at' => $window['start']->copy()->subDay()->format('Y-m-d H:i:s'),
    ]);
    for ($day = 0; $day < 28; $day++) {
        $at = $window['start']->copy()->addDays($day)->addHour()->format('Y-m-d H:i:s');
        DB::table('service_checks')->insert([
            'monitored_service_id' => $serviceId, 'checked_at' => $at, 'source' => 'automatic',
            'check_type' => 'http', 'is_during_maintenance' => false, 'is_success' => true,
            'response_time_ms' => 100 + $day, 'status_code' => 200,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }
    $children = [];
    foreach ([1, 2] as $instance) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork concurrent scheduler probe.');
        }
        if ($pid === 0) {
            DB::disconnect();
            $result = Artisan::call('slo:prepare-due');
            echo json_encode(['scheduler_instance' => $instance, 'exit_code' => $result,
                'output' => trim(Artisan::output())], JSON_THROW_ON_ERROR)."\n";
            exit($result);
        }
        $children[] = $pid;
    }
    $allExited = true;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $allExited = $allExited && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    }
    $queued = DB::table('jobs')->where('queue', 'slo-calibration')->count();
    echo json_encode(['mode' => $mode, 'service_id' => $serviceId,
        'both_schedulers_succeeded' => $allExited, 'queued_slo_jobs' => $queued,
        'existing_proposals_for_new_service' => DB::table('slo_proposals')->where('monitored_service_id', $serviceId)->count()], JSON_THROW_ON_ERROR)."\n";
    exit($allExited && $queued === 1 ? 0 : 1);
}

if ($mode === 'dispatch-interference') {
    Carbon::setTestNow();
    if (DB::table('monitored_services')->where('name', 'like', 'b5b-interference-check-%')->count() !== 10) {
        throw new RuntimeException('Stage the disposable interference fixture first.');
    }
    $started = microtime(true);
    Artisan::call('slo:prepare-due');
    $sloOutput = trim(Artisan::output());
    $sloSeconds = microtime(true) - $started;
    $started = microtime(true);
    Artisan::call('services:check-due');
    $checkSeconds = microtime(true) - $started;
    echo json_encode(['mode' => $mode, 'slo_dispatch' => $sloOutput,
        'slo_dispatch_seconds' => round($sloSeconds, 3),
        'check_dispatch_seconds' => round($checkSeconds, 3),
        'jobs_by_queue' => DB::table('jobs')->selectRaw('queue, COUNT(*) AS n')->groupBy('queue')->get(),
        'dispatched_at_unix' => microtime(true)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'work-concurrent-interference') {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('The bounded two-worker probe requires pcntl.');
    }
    Carbon::setTestNow();
    $dispatchStart = microtime(true);
    Artisan::call('slo:prepare-due');
    $sloOutput = trim(Artisan::output());
    $sloDispatchSeconds = microtime(true) - $dispatchStart;
    $checkStart = microtime(true);
    Artisan::call('services:check-due');
    $checkDispatchSeconds = microtime(true) - $checkStart;
    $pending = DB::table('jobs')->selectRaw('queue, COUNT(*) AS n')->groupBy('queue')->pluck('n', 'queue');
    if ((int) $pending->get('slo-calibration') !== 1 || (int) $pending->get('default') !== 10) {
        throw new RuntimeException('Expected exactly one high-volume SLO job and ten local monitoring jobs.');
    }
    echo json_encode(['mode' => $mode, 'slo_dispatch' => $sloOutput,
        'slo_dispatch_seconds' => round($sloDispatchSeconds, 3),
        'check_dispatch_seconds' => round($checkDispatchSeconds, 3),
        'all_jobs_enqueued_by_unix' => microtime(true)], JSON_THROW_ON_ERROR)."\n";
    $children = [];
    foreach (['slo-calibration', 'default'] as $queueName) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not start an isolated worker process.');
        }
        if ($pid === 0) {
            DB::disconnect();
            $argv[2] = $queueName;
            $mode = 'work-interference';
            break;
        }
        $children[] = $pid;
    }
    if ($mode === 'work-concurrent-interference') {
        $failed = false;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $failed = $failed || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
        }
        exit($failed ? 1 : 0);
    }
}

if ($mode === 'work-control') {
    Carbon::setTestNow();
    if (DB::table('monitored_services')->where('name', 'like', 'b5b-interference-check-%')->count() !== 10
        || DB::table('jobs')->exists()
        || DB::table('monitored_services')->where('name', 'like', 'b5b-control-check-%')->exists()) {
        throw new RuntimeException('Expected the completed isolated interference run before its control.');
    }
    DB::table('monitored_services')->where('name', 'like', 'b5b-interference-check-%')->update(['is_active' => false]);
    DB::table('monitored_services')->whereIn('id', DB::table('slo_proposals')->select('monitored_service_id'))
        ->update(['is_active' => false]);
    for ($i = 1; $i <= 10; $i++) {
        DB::table('monitored_services')->insert([
            'name' => "b5b-control-check-{$i}", 'url' => 'http://127.0.0.1:18081/up',
            'check_type' => 'http', 'check_interval_minutes' => 1, 'is_active' => true,
            'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'), 'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }
    $dispatchStart = microtime(true);
    Artisan::call('services:check-due');
    $dispatchSeconds = microtime(true) - $dispatchStart;
    if (DB::table('jobs')->where('queue', 'default')->count() !== 10) {
        throw new RuntimeException('Expected exactly ten local control monitoring jobs.');
    }
    echo json_encode(['mode' => 'work-control', 'check_dispatch_seconds' => round($dispatchSeconds, 3),
        'all_jobs_enqueued_by_unix' => microtime(true)], JSON_THROW_ON_ERROR)."\n";
    $argv[2] = 'default';
    $mode = 'work-interference';
}

if ($mode === 'work-interference') {
    Carbon::setTestNow();
    $queueName = $argv[2] ?? '';
    if (! in_array($queueName, ['default', 'slo-calibration'], true)) {
        throw new InvalidArgumentException('Expected default or slo-calibration queue.');
    }
    $events = [];
    $started = [];
    $queryTimes = [];
    DB::listen(function ($query) use (&$queryTimes): void {
        $queryTimes[] = $query->time;
    });
    $usageBefore = getrusage();
    Queue::before(function ($event) use (&$started): void {
        $started[$event->job->getJobId()] = microtime(true);
    });
    Queue::after(function ($event) use (&$started, &$events): void {
        $id = $event->job->getJobId();
        $events[] = ['job_id' => $id, 'started_unix' => $started[$id],
            'finished_unix' => microtime(true),
            'execution_ms' => round((microtime(true) - $started[$id]) * 1000, 3)];
    });
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queueName,
        '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3,
        '--timeout' => 60, '--max-time' => 120, '--max-jobs' => $queueName === 'default' ? 10 : 1]);
    $usageAfter = getrusage();
    sort($queryTimes);
    $queryP95 = null;
    if ($queryTimes !== []) {
        $position = .95 * (count($queryTimes) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $queryP95 = round($queryTimes[$lower] + ($position - $lower) * ($queryTimes[$upper] - $queryTimes[$lower]), 3);
    }
    echo json_encode(['mode' => $mode, 'queue' => $queueName, 'events' => $events,
        'remaining' => DB::table('jobs')->where('queue', $queueName)->count(),
        'failed' => DB::table('failed_jobs')->count(),
        'sql_queries' => count($queryTimes), 'sql_query_p95_ms' => $queryP95,
        'cpu_user_ms' => round(($usageAfter['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec']) * 1000
            + ($usageAfter['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec']) / 1000, 3),
        'cpu_system_ms' => round(($usageAfter['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec']) * 1000
            + ($usageAfter['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']) / 1000, 3),
        'peak_php_mb' => round(memory_get_peak_usage(true) / 1048576, 3)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'result-interference') {
    Carbon::setTestNow();
    echo json_encode(['mode' => $mode,
        'checks' => DB::table('service_checks')->whereIn('monitored_service_id',
            DB::table('monitored_services')->select('id')->where('name', 'like', 'b5b-interference-check-%'))
            ->selectRaw('COUNT(*) AS n, SUM(is_success) AS successful')->first(),
        'drafts' => DB::table('slo_proposals')->where('status', 'draft')->count(),
        'jobs_by_queue' => DB::table('jobs')->selectRaw('queue, COUNT(*) AS n')->groupBy('queue')->get(),
        'failed' => DB::table('failed_jobs')->count()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'probe') {
    $serviceId = DB::table('monitored_services')->where('name', 'b5b-queue-50-1')->value('id');
    if ($serviceId === null) {
        throw new RuntimeException('Seed the disposable workload first.');
    }
    $times = [];
    for ($i = 0; $i < 400; $i++) {
        $start = hrtime(true);
        DB::table('service_checks')->where('monitored_service_id', $serviceId)->latest('id')->value('id');
        $times[] = (hrtime(true) - $start) / 1e6;
        usleep(5000);
    }
    sort($times);
    echo json_encode([
        'mode' => 'probe', 'samples' => count($times),
        'median_ms' => round($times[200], 3),
        'p95_ms_type7' => round($times[379] + .05 * ($times[380] - $times[379]), 3),
        'maximum_ms' => round(max($times), 3),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}

fwrite(STDERR, "Usage: php tests/Benchmarks/slo_performance.php seed 40000|100000|250000|500000 | measure | dispatch 1|10|50 | queue 1|10|50 | probe | stage-interference | dispatch-interference | work-interference default|slo-calibration | work-concurrent-interference | work-control | result-interference | stage-sustained | reset-sustained | run-sustained baseline|concurrent [nightly] | concurrent-dispatch\n");
exit(2);
