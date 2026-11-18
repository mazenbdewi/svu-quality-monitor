<?php

// Optional timing observer for the isolated Docker workload. Uses the real
// Laravel worker and real jobs; metrics are separate from research tables.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('BENCHMARK_FIXTURE_ENABLED') !== '1' || DB::connection()->getDatabaseName() !== 'svu_remediation_workload') {
    throw new RuntimeException('Isolated workload database required.');
}
$started = [];
Event::listen(JobProcessing::class, function ($event) use (&$started): void {
    $started[$event->job->getJobId()] = hrtime(true);
});
Event::listen(JobProcessed::class, function ($event) use (&$started): void {
    $key = $event->job->getJobId();
    $row = [
        'job_id' => $key, 'queue' => $event->job->getQueue(),
        'class' => $event->job->resolveName(), 'finished_at' => microtime(true),
        'duration_ms' => (hrtime(true) - $started[$key]) / 1e6,
        'peak_php_bytes' => memory_get_peak_usage(true),
    ];
    file_put_contents(storage_path('app/remediation-job-timings.jsonl'), json_encode($row).PHP_EOL, FILE_APPEND | LOCK_EX);
    unset($started[$key]);
});
exit($app->make(Kernel::class)->call('queue:work', [
    '--queue' => $argv[1] ?? 'default', '--sleep' => 1, '--tries' => 3, '--timeout' => 60,
]));
