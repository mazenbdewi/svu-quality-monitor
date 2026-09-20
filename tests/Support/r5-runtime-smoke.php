<?php

// Executed only in an explicitly isolated R5 container; no Factory/Faker dependency.
use App\Jobs\EvaluateSpcPhaseTwo;
use App\Models\MonitoredService;
use App\Models\SpcIncidentLink;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcSignal;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Research\ResearchPackage;
use App\Services\Spc\PhaseTwoEvaluator;
use App\Services\Spc\SpcResearchEvaluation;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Faker\Factory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ResearchMigrationScenario;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('RESEARCH_ISOLATED_TEST') !== 'true' || ! str_starts_with(DB::connection()->getDatabaseName(), 'r5_')) {
    throw new RuntimeException('Isolated R5 database required.');
}
$mode = $argv[1] ?? 'smoke';
if ($mode === 'upgrade') {
    require __DIR__.'/ResearchMigrationScenario.php';
    echo json_encode(ResearchMigrationScenario::run(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($mode === 'clock') {
    echo json_encode(['php_timezone' => date_default_timezone_get(), 'utc' => now()->utc()->toIso8601String(), 'local' => now()->setTimezone('Asia/Damascus')->toIso8601String(), 'analysis_timezone' => config('monitoring.spc.analysis_timezone'), 'db' => DB::selectOne('select UTC_TIMESTAMP() as utc_now, NOW() as session_now, @@session.time_zone as session_timezone'), 'health' => app(SystemHealthService::class)->snapshot()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit;
}
if ($mode === 'delays') {
    $rows = SpcMonitoringEvaluation::whereNotNull('context->job_timing->job_started_at')->get()->map(function ($e) {
        return ['evaluation_id' => $e->id, 'detected_at' => $e->detected_at->toIso8601String(), 'job_timing' => $e->context['job_timing'], 'points' => SpcMonitoringPoint::where('evaluation_id', $e->id)->get()->map(fn ($p) => ['source_persisted_at' => $p->context['available_at'] ?? null, 'detected_at' => $p->detected_at->toIso8601String()])->all()];
    });
    if ($rows->isEmpty()) {
        throw new RuntimeException('No queued evaluation timing captured');
    }
    MonitoredService::query()->update(['is_active' => false]);
    echo json_encode($rows, JSON_PRETTY_PRINT)."\n";
    exit;
}
if ($mode === 'pages') {
    $admin = User::where('email', 'r5@example.invalid')->firstOrFail();
    Auth::login($admin);
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $result = [];
    foreach (['/admin/login', '/admin', '/admin/monitored-services', '/admin/reliability-metrics', '/admin/control-charts', '/admin/control-chart-baselines', '/admin/spc-research-monitoring', '/admin/reports'] as $path) {
        $request = Request::create('http://localhost'.$path);
        $response = $kernel->handle($request);
        $result[$path] = $response->getStatusCode();
        $kernel->terminate($request, $response);
        if (($path !== '/admin/login' && $response->getStatusCode() !== 200) || $response->getStatusCode() >= 400) {
            throw new RuntimeException('Page failed: '.$path.' status '.$response->getStatusCode());
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT)."\n";
    exit;
}
app(DatabaseSeeder::class)->run();
if (class_exists(Factory::class)) {
    throw new RuntimeException('Expected production image without Faker.');
}
$admin = User::firstOrCreate(['email' => 'r5@example.invalid'], ['name' => 'Isolated R5 researcher', 'password' => Hash::make('isolated-test-only'), 'is_active' => true]);
$admin->assignRole('administrator');
$service = MonitoredService::create(['name' => 'Isolated R5 HTTP', 'url' => 'https://example.invalid', 'check_type' => 'http', 'created_at' => now()->subDays(4), 'check_interval_minutes' => 5, 'warning_response_ms' => 1500, 'critical_response_ms' => 3000, 'expected_status_code' => 200, 'is_active' => true]);
$service->forceFill(['created_at' => now()->subDays(4)])->save();
$check = function (Carbon $at, int $value, bool $slow = false) use ($service) {
    $c = $service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => $slow, 'performance_status' => $slow ? 'warning' : 'healthy', 'response_time_ms' => $value, 'is_during_maintenance' => false]);
    $c->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $c;
};
$start = now()->utc()->subDays(2)->startOfDay();
foreach ([100, 110, 130, 160] as $i => $value) {
    $check($start->copy()->addMinutes($i * 5), $value);
}
$engine = app(PhaseOneBaselineService::class);
$b = $engine->create($service, 'i_chart', $start->toIso8601String(), $start->copy()->addMinutes(20)->toIso8601String(), 'UTC', $admin);
$engine->review($b, $admin, 'Isolated workflow verification; not a study baseline', true);
$engine->approve($b, $admin, 'Isolated smoke only');
$initial = $b->fresh()->toArray();
$t = now()->utc()->startOfSecond();
if ($mode === 'queue') {
    $check($t, 999);
    Bus::dispatch(new EvaluateSpcPhaseTwo($service->id));
    echo json_encode(['queued_service_id' => $service->id, 'baseline_id' => $b->id, 'persisted_at' => $t->toIso8601String()])."\n";
    exit;
}
if ($mode === 'p') {
    Carbon::setTestNow($t->copy()->startOfHour());
    // Two complete historical subgroups are required even for this tiny workflow fixture.
    foreach (range(4, 23) as $i) {
        $check($start->copy()->addMinutes($i * 5), 125);
    }
    $pb = $engine->create($service, 'p_chart', $start->toIso8601String(), $start->copy()->addHours(2)->toIso8601String(), 'UTC', $admin);
    $engine->review($pb, $admin, 'Isolated P reference', true);
    $engine->approve($pb, $admin, 'Isolated P reference');
    $hour = now()->copy();
    foreach (range(0, 11) as $i) {
        $check($hour->copy()->addMinutes(5 * $i), 1600, true);
    }
    Carbon::setTestNow($hour->copy()->addMinutes(59));
    app(PhaseTwoEvaluator::class)->evaluate($service);
    if (SpcMonitoringPoint::where('baseline_id', $pb->id)->exists()) {
        throw new RuntimeException('Premature P bucket');
    }
    Carbon::setTestNow($hour->copy()->addHour());
    app(PhaseTwoEvaluator::class)->evaluate($service);
    $point = SpcMonitoringPoint::where('baseline_id', $pb->id)->firstOrFail();
    if ((int) $point->subgroup_size !== 12 || (float) $point->center_line !== 0.0 || $point->status !== 'eligible') {
        throw new RuntimeException('P parameters mismatch');
    }
    Carbon::setTestNow($hour->copy()->addHours(2));
    app(PhaseTwoEvaluator::class)->evaluate($service);
    if (SpcMonitoringPoint::where('baseline_id', $pb->id)->latest('id')->value('status') !== 'missing' || SpcSignal::where('baseline_id', $pb->id)->count() !== 1) {
        throw new RuntimeException('Missing P bucket mismatch');
    }
    $service->update(['is_active' => false]);
    Carbon::setTestNow();
    echo json_encode(['p0' => $point->center_line, 'n' => $point->subgroup_size, 'detected_at' => $point->detected_at->toIso8601String(), 'before_close' => 'no point', 'missing' => 'no signal'])."\n";
    exit;
}
$check($t, 125);
app(PhaseTwoEvaluator::class)->evaluate($service);
if (SpcSignal::where('baseline_id', $b->id)->exists()) {
    throw new RuntimeException('Normal point signalled');
}
// Fake clock is restricted to this synthetic isolated process. Production code still uses the live clock.
Carbon::setTestNow($t->copy()->addMinute());
$check(now(), 999);
app(PhaseTwoEvaluator::class)->evaluate($service);
$signal = SpcSignal::where('baseline_id', $b->id)->firstOrFail();
for ($minute = 2; $minute <= 65; $minute++) {
    Carbon::setTestNow($t->copy()->addMinutes($minute));
    $check(now(), 125);
    app(PhaseTwoEvaluator::class)->evaluate($service);
}
$incident = $service->serviceIncidents()->create(['started_at' => $t->copy()->addMinutes(10), 'confirmed_at' => $t->copy()->addMinutes(11), 'status' => 'open', 'incident_type' => 'availability', 'severity' => 'critical']);
$run = app(SpcResearchEvaluation::class)->run($t, $t->copy()->addHour());
if ($run->metrics['groups'][0]['incidents_with_prior_episode'] !== 1) {
    throw new RuntimeException('Live association failed');
}
app(PhaseTwoEvaluator::class)->evaluate($service, 'retrospective', $t->copy()->addMinute());
$retro = app(SpcResearchEvaluation::class)->run($t, $t->copy()->addHour(), 'retrospective');
$service->update(['warning_response_ms' => 1700]);
app(PhaseTwoEvaluator::class)->evaluate($service);
if (SpcMonitoringEvaluation::where('baseline_id', $b->id)->latest('id')->value('status') !== 'baseline_incompatible') {
    throw new RuntimeException('Mismatch did not block');
}
$engine->retire($b, $admin, 'Isolated configuration transition');
$v2 = $engine->create($service, 'i_chart', $start->toIso8601String(), $start->copy()->addMinutes(20)->toIso8601String(), 'UTC', $admin);
$engine->review($v2, $admin, 'Isolated new reference', true);
$engine->approve($v2, $admin, 'Isolated new reference');
Carbon::setTestNow(now()->addMinute());
$check(now(), 999);
app(PhaseTwoEvaluator::class)->evaluate($service);
if (! SpcSignal::where('baseline_id', $v2->id)->exists()) {
    throw new RuntimeException('New version did not resume');
}
foreach (['parameters', 'membership_digest', 'configuration_snapshot'] as $key) {
    if ($initial[$key] !== $b->fresh()->toArray()[$key]) {
        throw new RuntimeException('Old baseline changed');
    }
}
$directory = '/tmp/r5-package-'.bin2hex(random_bytes(5));
$manifest = app(ResearchPackage::class)->export($start, now(), $directory);
$service->update(['is_active' => false]);
Carbon::setTestNow();
echo json_encode(['normal' => 'no signal', 'out_of_control' => 'live signal', 'signal_detected_at' => $signal->detected_at->toIso8601String(), 'lead_seconds' => SpcIncidentLink::where('research_run_id', $run->id)->value('lead_seconds'), 'live_run_id' => $run->id, 'retrospective_run_id' => $retro->id, 'new_baseline_version' => $v2->version, 'package' => $directory, 'package_files' => count($manifest['sha256']), 'production_faker' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
