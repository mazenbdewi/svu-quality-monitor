<?php

/** Disposable MySQL-only, bounded monitoring/SLO interference acceptance probe. */

use App\Jobs\CheckMonitoredServiceJob;
use App\Jobs\PrepareAutomaticSloProposal;
use App\Models\SloProposal;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'Operational acceptance probe failed: '.$exception::class.PHP_EOL);
    exit(1);
});

$token = getenv('SVU_BENCHMARK_GUARD_TOKEN');
$uuid = getenv('SVU_BENCHMARK_SERVER_UUID');
if (getenv('SVU_BENCHMARK_ALLOW') !== 'yes'
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 33071
    || config('database.connections.mysql.database') !== 'svu_b5b_disposable'
    || config('database.connections.mysql.username') !== 'svu_b5b_only'
    || ! is_string($token) || ! preg_match('/^[a-f0-9]{48}$/', $token)
    || ! is_string($uuid) || ! preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
    fwrite(STDERR, "Refusing probe outside the named disposable MySQL database.\n");
    exit(2);
}
$server = DB::selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version');
if ($server->db !== 'svu_b5b_disposable' || ! hash_equals(strtolower($uuid), strtolower($server->uuid))
    || ! str_starts_with($server->version, '8.4.')
    || ! hash_equals($token, (string) DB::table('svu_benchmark_guard')->where('id', 1)->value('token'))) {
    fwrite(STDERR, "Refusing probe: disposable server identity or one-time marker mismatch.\n");
    exit(2);
}
if (disk_free_space('/tmp') < 8 * 1024 ** 3) {
    throw new RuntimeException('At least 8 GiB free disk is required.');
}
ini_set('memory_limit', '256M');

$mode = $argv[1] ?? '';
$phase = $argv[2] ?? '';
if ($mode === 'stage') {
    if (Schema::hasTable('b5c3_events') || DB::table('service_checks')->count() !== 500000
        || DB::table('jobs')->exists() || DB::table('slo_proposals')->exists()
        || DB::table('monitored_services')->where('name', 'like', 'b5c3-%')->exists()) {
        throw new RuntimeException('Expected the fresh guarded 500,000-check fixture, with no prior acceptance run.');
    }
    DB::table('monitored_services')->update(['is_active' => false]);
    $bulk = DB::table('monitored_services')->where('name', 'like', 'b5b-bulk-%')->orderBy('id')->get(['id', 'check_type']);
    if ($bulk->count() !== 50) {
        throw new RuntimeException('Expected exactly 50 high-volume historical services.');
    }
    $now = now()->utc()->format('Y-m-d H:i:s');
    foreach ($bulk as $service) {
        DB::table('monitored_services')->where('id', $service->id)->update(['is_active' => true, 'check_interval_minutes' => 1440]);
        // A current manual marker keeps the historical example URL out of the
        // operational check queue; it is after the frozen calibration cutoff.
        DB::table('service_checks')->insert([
            'monitored_service_id' => $service->id, 'checked_at' => $now, 'source' => 'manual',
            'check_type' => $service->check_type, 'is_during_maintenance' => false,
            'is_success' => true, 'response_time_ms' => 100, 'status_code' => 200,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach (['baseline', 'concurrent'] as $name) {
        for ($index = 1; $index <= 20; $index++) {
            DB::table('monitored_services')->insert([
                'name' => "b5c3-{$name}-{$index}", 'url' => 'http://127.0.0.1:18081/up',
                'check_type' => $index % 3 === 0 ? 'api' : 'http',
                'check_interval_minutes' => 1, 'is_active' => false,
                'warning_response_ms' => 1500, 'critical_response_ms' => 3000,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
    Schema::create('b5c3_events', static function (Blueprint $table): void {
        $table->id();
        $table->string('phase', 20);
        $table->string('queue', 30);
        $table->unsignedBigInteger('service_id');
        $table->unsignedBigInteger('job_id');
        $table->double('dispatch_wall');
        $table->unsignedBigInteger('dispatch_mono');
        $table->double('start_wall');
        $table->unsignedBigInteger('start_mono');
        $table->double('finish_wall')->nullable();
        $table->unsignedBigInteger('finish_mono')->nullable();
        $table->boolean('failed')->default(false);
        $table->index(['phase', 'queue', 'service_id']);
    });
    echo json_encode(['staged' => true, 'mysql' => $server->version,
        'historical_checks' => 500000, 'heavy_services' => 50, 'monitors_per_phase' => 20], JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode === 'reset-aborted-baseline') {
    if (! Schema::hasTable('b5c3_events') || DB::table('jobs')->exists()
        || DB::table('slo_proposals')->exists()) {
        throw new RuntimeException('Refusing reset while jobs or frozen proposals exist.');
    }
    $ids = DB::table('monitored_services')->where('name', 'like', 'b5c3-baseline-%')->pluck('id');
    if ($ids->count() !== 20) {
        throw new RuntimeException('Expected exactly 20 disposable baseline monitors.');
    }
    $removed = DB::table('service_checks')->whereIn('monitored_service_id', $ids)->delete();
    DB::table('b5c3_events')->where('phase', 'baseline')->delete();
    DB::table('monitored_services')->whereIn('id', $ids)->update(['is_active' => false]);
    echo json_encode(['reset_aborted_baseline' => true, 'synthetic_checks_removed' => $removed], JSON_THROW_ON_ERROR)."\n";
    exit;
}

if ($mode !== 'run' || ! in_array($phase, ['baseline', 'concurrent'], true)
    || ! Schema::hasTable('b5c3_events') || DB::table('jobs')->exists()
    || DB::table('b5c3_events')->where('phase', $phase)->exists()
    || DB::table('monitored_services')->where('name', 'like', "b5c3-{$phase}-%")->count() !== 20) {
    throw new RuntimeException('Stage the disposable fixture, then run baseline and concurrent once each.');
}
if ($phase === 'baseline' && DB::table('slo_proposals')->exists()) {
    throw new RuntimeException('The baseline must run before calibration.');
}
if (! config('monitoring.slo.automatic_preparation_enabled') && $phase === 'concurrent') {
    throw new RuntimeException('Explicitly enable SLO preparation in this disposable process only.');
}
$monitorIds = DB::table('monitored_services')->where('name', 'like', "b5c3-{$phase}-%")->orderBy('id')->pluck('id')->all();
DB::table('monitored_services')->where('name', 'like', 'b5c3-%')->update(['is_active' => false]);
DB::table('monitored_services')->whereIn('id', $monitorIds)->update(['is_active' => true]);
Queue::createPayloadUsing(static function (string $connection, string $queue, array $payload): array {
    // Laravel invokes this hook before serialization AND before the database
    // enqueue. This is a dispatch-intent marker, not an enqueue-commit time.
    $job = $payload['data']['command'] ?? null;
    $serviceId = $job instanceof CheckMonitoredServiceJob ? $job->monitoredServiceId
        : ($job instanceof PrepareAutomaticSloProposal ? $job->serviceId : null);

    return $serviceId === null ? [] : ['b5c3_service_id' => $serviceId,
        'b5c3_dispatch_wall' => microtime(true), 'b5c3_dispatch_mono' => hrtime(true)];
});

$startWall = microtime(true);
$startMono = hrtime(true);
$cutoff = $startWall + 300;
$graceEnd = $cutoff + 105;
$sloDispatch = null;
if ($phase === 'concurrent') {
    $started = hrtime(true);
    if (Artisan::call('slo:prepare-due') !== 0) {
        throw new RuntimeException('SLO scheduler command failed in the disposable fixture.');
    }
    $sloDispatch = (hrtime(true) - $started) / 1e9;
    if (DB::table('jobs')->where('queue', 'slo-calibration')->count() !== 50) {
        throw new RuntimeException('Expected exactly 50 high-volume calibration jobs.');
    }
}

$workers = [];
foreach ($phase === 'concurrent' ? ['default', 'slo-calibration'] : ['default'] as $queueName) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork the bounded test worker.');
    }
    if ($pid === 0) {
        DB::disconnect();
        $queryTimes = [];
        DB::listen(static function ($query) use (&$queryTimes): void {
            if (count($queryTimes) < 50000) {
                $queryTimes[] = $query->time;
            }
        });
        Queue::before(static function ($event) use ($phase): void {
            $payload = $event->job->payload();
            if (! isset($payload['b5c3_service_id'])) {
                return;
            }
            DB::table('b5c3_events')->insert([
                'phase' => $phase, 'queue' => $event->job->getQueue(),
                'service_id' => $payload['b5c3_service_id'], 'job_id' => $event->job->getJobId(),
                'dispatch_wall' => $payload['b5c3_dispatch_wall'],
                'dispatch_mono' => $payload['b5c3_dispatch_mono'],
                'start_wall' => microtime(true), 'start_mono' => hrtime(true),
            ]);
        });
        Queue::after(static function ($event) use ($phase): void {
            DB::table('b5c3_events')->where('phase', $phase)->where('job_id', $event->job->getJobId())
                ->orderByDesc('id')->limit(1)->update(['finish_wall' => microtime(true), 'finish_mono' => hrtime(true)]);
        });
        Queue::failing(static function ($event) use ($phase): void {
            DB::table('b5c3_events')->where('phase', $phase)->where('job_id', $event->job->getJobId())
                ->orderByDesc('id')->limit(1)->update(['failed' => true]);
        });
        $usage = getrusage();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queueName,
            '--sleep' => 3, '--tries' => 3, '--timeout' => 60, '--max-time' => 420,
            '--max-jobs' => $queueName === 'default' ? 120 : 50]);
        $after = getrusage();
        sort($queryTimes);
        $p95 = $queryTimes === [] ? null : $queryTimes[(int) ceil(.95 * count($queryTimes)) - 1];
        echo json_encode(['worker' => $queueName, 'phase' => $phase,
            'sql_queries' => count($queryTimes), 'sql_p95_ms' => $p95,
            'cpu_user_s' => round($after['ru_utime.tv_sec'] - $usage['ru_utime.tv_sec']
                + ($after['ru_utime.tv_usec'] - $usage['ru_utime.tv_usec']) / 1e6, 3),
            'php_peak_mib' => round(memory_get_peak_usage(true) / 1048576, 2)], JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    $workers[$queueName] = $pid;
}

$sampleAvailable = static function (): int {
    $content = file_get_contents('/proc/meminfo');
    preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $content, $match);

    return (int) ($match[1] ?? 0) * 1024;
};
$minAvailable = $sampleAvailable();
$maxDefaultBacklog = 0;
$maxSloBacklog = 0;
$ticks = [];
foreach ([0, 60, 120, 180, 240] as $offset) {
    $targetMono = $startMono + (int) ($offset * 1e9);
    while (hrtime(true) < $targetMono) {
        $minAvailable = min($minAvailable, $sampleAvailable());
        $maxDefaultBacklog = max($maxDefaultBacklog, DB::table('jobs')->where('queue', 'default')->count());
        $maxSloBacklog = max($maxSloBacklog, DB::table('jobs')->where('queue', 'slo-calibration')->count());
        usleep(250000);
    }
    $commandStartWall = microtime(true);
    $commandStartMono = hrtime(true);
    if (Artisan::call('services:check-due') !== 0) {
        throw new RuntimeException('Monitoring dispatcher failed.');
    }
    $output = Artisan::output();
    preg_match('/Due services count:\s*(\d+)/', $output, $dueMatch);
    preg_match('/Dispatched services count:\s*(\d+)/', $output, $dispatchedMatch);
    preg_match('/Failed dispatches count:\s*(\d+)/', $output, $failedMatch);
    $ticks[] = ['planned_offset_s' => $offset,
        'command_start_delay_s' => round(($commandStartMono - $targetMono) / 1e9, 3),
        'command_duration_s' => round((hrtime(true) - $commandStartMono) / 1e9, 3),
        'due_at_poll' => isset($dueMatch[1]) ? (int) $dueMatch[1] : null,
        'dispatched_at_poll' => isset($dispatchedMatch[1]) ? (int) $dispatchedMatch[1] : null,
        'dispatch_failures' => isset($failedMatch[1]) ? (int) $failedMatch[1] : null,
        'command_start_utc' => gmdate('Y-m-d H:i:s', (int) $commandStartWall)];
}
while (hrtime(true) < $startMono + (int) 300e9) {
    $minAvailable = min($minAvailable, $sampleAvailable());
    $maxDefaultBacklog = max($maxDefaultBacklog, DB::table('jobs')->where('queue', 'default')->count());
    $maxSloBacklog = max($maxSloBacklog, DB::table('jobs')->where('queue', 'slo-calibration')->count());
    usleep(250000);
}
$cutoffJobs = DB::table('jobs')->get(['queue', 'payload', 'reserved_at', 'attempts']);
$cutoffEvents = DB::table('b5c3_events')->where('phase', $phase)->get();
$cutoffDrafts = DB::table('slo_proposals')->count();
while (hrtime(true) < $startMono + (int) 405e9) {
    $minAvailable = min($minAvailable, $sampleAvailable());
    $maxDefaultBacklog = max($maxDefaultBacklog, DB::table('jobs')->where('queue', 'default')->count());
    $maxSloBacklog = max($maxSloBacklog, DB::table('jobs')->where('queue', 'slo-calibration')->count());
    usleep(250000);
}
foreach ($workers as $pid) {
    posix_kill($pid, SIGTERM);
}
foreach ($workers as $pid) {
    pcntl_waitpid($pid, $status);
}

$events = DB::table('b5c3_events')->where('phase', $phase)->orderBy('start_wall')->get();
$pendingByService = [];
foreach ($cutoffJobs as $row) {
    $payload = json_decode($row->payload, true);
    if (isset($payload['b5c3_service_id'])) {
        $pendingByService[(int) $payload['b5c3_service_id']][] = $payload;
    }
}
$distribution = static function (array $values): array {
    if ($values === []) {
        return ['n' => 0, 'p50' => null, 'p95' => null, 'p99' => null, 'max' => null];
    }
    sort($values);
    $one = static function (float $p) use ($values): float {
        $h = $p * (count($values) - 1);
        $lo = (int) floor($h);
        $hi = (int) ceil($h);

        return round($values[$lo] + ($h - $lo) * ($values[$hi] - $values[$lo]), 3);
    };

    return ['n' => count($values), 'p50' => $one(.50), 'p95' => $one(.95),
        'p99' => $one(.99), 'max' => round(max($values), 3)];
};
$ledger = ['due_before_cutoff' => 0, 'not_yet_due_at_cutoff' => 0,
    'completed_success_at_cutoff' => 0, 'completed_failure_at_cutoff' => 0,
    'running_at_cutoff' => 0, 'dispatched_pending_at_cutoff' => 0,
    'due_not_dispatched_at_cutoff' => 0, 'completed_success_by_grace' => 0,
    'completed_failure_by_grace' => 0, 'uncompleted_by_grace' => 0];
$dispatchDelay = [];
$queueWait = [];
$execution = [];
$endLateness = [];
foreach ($monitorIds as $id) {
    $mine = $events->filter(fn ($event) => $event->queue === 'default' && (int) $event->service_id === $id)->values();
    $checks = DB::table('service_checks')->where('monitored_service_id', $id)->where('source', 'automatic')
        ->orderBy('checked_at')->get(['checked_at', 'is_success']);
    $due = $startWall;
    for ($index = 0; $index < 8 && $due < $cutoff; $index++) {
        $ledger['due_before_cutoff']++;
        $event = $mine->get($index);
        $check = $checks->get($index);
        $pending = $pendingByService[$id][$index - $mine->count()] ?? null;
        if ($event && $event->finish_wall !== null && (float) $event->finish_wall <= $cutoff && $check) {
            $ledger[$check->is_success ? 'completed_success_at_cutoff' : 'completed_failure_at_cutoff']++;
        } elseif ($event && (float) $event->start_wall <= $cutoff && ($event->finish_wall === null || (float) $event->finish_wall > $cutoff)) {
            $ledger['running_at_cutoff']++;
        } elseif ($event || $pending) {
            $ledger['dispatched_pending_at_cutoff']++;
        } else {
            $ledger['due_not_dispatched_at_cutoff']++;
        }
        if ($event && $event->finish_wall !== null && (float) $event->finish_wall <= $graceEnd && $check) {
            $ledger[$check->is_success ? 'completed_success_by_grace' : 'completed_failure_by_grace']++;
            $dispatchDelay[] = (float) $event->dispatch_wall - $due;
            $queueWait[] = ((int) $event->start_mono - (int) $event->dispatch_mono) / 1e9;
            $execution[] = ((int) $event->finish_mono - (int) $event->start_mono) / 1e9;
            $endLateness[] = (float) $event->finish_wall - $due;
            $due = Carbon::parse($check->checked_at, 'UTC')->timestamp + 60;

            continue;
        }
        $ledger['uncompleted_by_grace']++;
        break;
    }
    if ($due >= $cutoff) {
        $ledger['not_yet_due_at_cutoff']++;
    }
}
$sloEvents = $events->where('queue', 'slo-calibration');
$sloWait = $sloEvents->map(fn ($e) => ((int) $e->start_mono - (int) $e->dispatch_mono) / 1e9)->all();
$sloDuration = $sloEvents->filter(fn ($e) => $e->finish_mono !== null)
    ->map(fn ($e) => ((int) $e->finish_mono - (int) $e->start_mono) / 1e9)->all();
$drafts = DB::table('slo_proposals')->get();
$integrity = true;
foreach ($drafts as $draft) {
    try {
        SloProposalService::assertIntegrity(SloProposal::findOrFail($draft->id));
    } catch (Throwable) {
        $integrity = false;
    }
}
$result = ['phase' => $phase, 'mysql' => $server->version,
    'historical_checks' => 500000, 'heavy_service_count' => 50,
    'monitor_count' => count($monitorIds), 'planned_polls' => count($ticks),
    'start_utc' => gmdate('Y-m-d H:i:s', (int) $startWall),
    'cutoff_utc' => gmdate('Y-m-d H:i:s', (int) $cutoff), 'grace_seconds' => 105,
    'ticks' => $ticks, 'ledger' => $ledger,
    'dispatch_marker' => 'payload_construction_before_database_enqueue',
    'due_to_dispatch_s' => $distribution($dispatchDelay),
    'dispatch_to_start_s' => $distribution($queueWait),
    'execution_s' => $distribution($execution),
    'due_to_finish_s' => $distribution($endLateness),
    'default_events_started' => $events->where('queue', 'default')->count(),
    'default_events_finished' => $events->where('queue', 'default')->whereNotNull('finish_wall')->count(),
    'default_queue_at_cutoff' => $cutoffJobs->where('queue', 'default')->count(),
    'default_queue_after_grace' => DB::table('jobs')->where('queue', 'default')->count(),
    'slo_dispatch_s' => $sloDispatch, 'slo_drafts_at_cutoff' => $cutoffDrafts,
    'slo_drafts_after_grace' => $drafts->count(), 'slo_integrity' => $integrity,
    'slo_queue_at_cutoff' => $cutoffJobs->where('queue', 'slo-calibration')->count(),
    'slo_queue_after_grace' => DB::table('jobs')->where('queue', 'slo-calibration')->count(),
    'slo_wait_s' => $distribution($sloWait), 'slo_execution_s' => $distribution($sloDuration),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'max_default_backlog' => $maxDefaultBacklog, 'max_slo_backlog' => $maxSloBacklog,
    'host_min_available_mib' => round($minAvailable / 1048576, 1),
    'elapsed_s' => round((hrtime(true) - $startMono) / 1e9, 3)];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit($ledger['uncompleted_by_grace'] === 0 && $ledger['completed_failure_by_grace'] === 0
    && $result['default_queue_after_grace'] === 0 && $result['failed_jobs'] === 0 ? 0 : 1);
