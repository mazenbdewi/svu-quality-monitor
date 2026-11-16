<?php

/**
 * Disposable-only SLO benchmark. Example:
 * SVU_BENCHMARK_ALLOW=yes DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33071 \
 * DB_DATABASE=svu_b5b_disposable DB_USERNAME=svu_b5b_only DB_PASSWORD=... \
 * SVU_BENCHMARK_SERVER_UUID=... SVU_BENCHMARK_GUARD_TOKEN=... \
 * CACHE_STORE=database QUEUE_CONNECTION=database php tests/Benchmarks/slo_performance.php seed 40000
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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
        'connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0,
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

fwrite(STDERR, "Usage: php tests/Benchmarks/slo_performance.php seed 40000|100000|250000|500000 | measure | dispatch 1|10|50 | queue 1|10|50 | probe\n");
exit(2);
