<?php

namespace Tests\Feature;

use App\Jobs\PrepareAutomaticPhaseOneBaseline;
use App\Models\BaselinePreparationRun;
use App\Models\MonitoredService;
use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BaselinePreparationSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_round_robin_has_no_starvation_for_5_6_20_and_100_incomplete_services(): void
    {
        Queue::fake();

        foreach ([5, 6, 20, 100] as $size) {
            BaselinePreparationRun::query()->delete();
            MonitoredService::query()->delete();
            MonitoredService::factory()->count($size)->create(['check_type' => 'http', 'is_active' => true]);
            DB::table('baseline_preparation_scheduler')->update(['last_service_id' => 0]);
            $days = 2 * (int) ceil($size / 5);

            for ($day = 0; $day < $days; $day++) {
                Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'UTC')->addDays($day));
                $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
                $this->assertSame(min(5, $size), $counts['dispatched'], "size={$size}, day={$day}");
                BaselinePreparationRun::query()->where('status', 'pending')->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            $this->assertSame($size, BaselinePreparationRun::query()->distinct()->count('monitored_service_id'), "size={$size}");
        }
    }

    public function test_durable_ledger_prevents_duplicate_accumulation_after_unique_locks_expire(): void
    {
        Queue::fake();
        config()->set('monitoring.spc.automatic_baseline.backlog_limit', 5);
        MonitoredService::factory()->count(20)->create(['check_type' => 'http', 'is_active' => true]);
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');

        $first = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        Cache::flush();
        Carbon::setTestNow('2026-11-19 12:00:00 UTC');
        $second = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        Carbon::setTestNow('2026-11-20 12:00:00 UTC');
        $third = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(5, $first['dispatched']);
        $this->assertSame(0, $second['dispatched']);
        $this->assertSame(5, $second['duplicate']);
        $this->assertSame(15, $second['deferred']);
        $this->assertSame(0, $third['dispatched']);
        $this->assertDatabaseCount('baseline_preparation_runs', 5);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, 5);
    }

    public function test_new_disabled_deleted_and_reenabled_services_preserve_fair_progress(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');
        $services = MonitoredService::factory()->count(6)->create(['check_type' => 'http', 'is_active' => true]);
        app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        BaselinePreparationRun::query()->update(['status' => 'completed', 'completed_at' => now()]);

        $deferred = $services->last();
        $deferred->update(['is_active' => false]);
        $services->first()->delete();
        $new = MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        Carbon::setTestNow('2026-11-19 12:00:00 UTC');
        app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        $this->assertTrue(BaselinePreparationRun::where('monitored_service_id', $new->id)->exists());
        $this->assertFalse(BaselinePreparationRun::where('monitored_service_id', $deferred->id)->exists());

        BaselinePreparationRun::query()->where('status', 'pending')->update(['status' => 'completed', 'completed_at' => now()]);
        $deferred->update(['is_active' => true]);
        Carbon::setTestNow('2026-11-20 12:00:00 UTC');
        app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        $this->assertTrue(BaselinePreparationRun::where('monitored_service_id', $deferred->id)->exists());
    }

    public function test_job_lifecycle_is_restart_safe_and_records_success_without_approval(): void
    {
        $service = MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        $run = BaselinePreparationRun::create([
            'monitored_service_id' => $service->id,
            'policy_version' => 'phase1-automatic-v1',
            'cutoff_at' => '2026-11-17 21:00:00',
            'analysis_timezone' => 'Asia/Damascus',
            'status' => 'pending',
            'dispatched_at' => now(),
        ]);
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');
        $job = new PrepareAutomaticPhaseOneBaseline(
            $service->id,
            null,
            '2026-11-17T21:00:00+00:00',
            'Asia/Damascus',
            'phase1-automatic-v1',
            $run->id,
        );

        $job->handle(app(AutomaticPhaseOneBaselineService::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->attempts);
        $this->assertDatabaseMissing('control_chart_baselines', ['status' => 'approved']);
        $this->assertDatabaseMissing('control_chart_baselines', ['active_key' => 1]);
    }

    public function test_real_database_queue_and_ui_share_durable_duplicates_and_a_limit_below_five(): void
    {
        config()->set('queue.default', 'database');
        config()->set('monitoring.spc.automatic_baseline.backlog_limit', 2);
        MonitoredService::factory()->count(6)->create(['check_type' => 'http', 'is_active' => true]);

        $first = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        Cache::flush();
        $second = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        $this->assertSame(2, $first['dispatched']);
        $this->assertSame(4, $first['deferred']);
        $this->assertSame(2, $second['duplicate']);
        $this->assertSame(0, $second['dispatched']);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('baseline_preparation_runs', 2);
    }

    public function test_shared_file_cache_supports_heartbeats_restart_signals_and_durable_dispatch(): void
    {
        $path = sys_get_temp_dir().'/svu-file-cache-'.bin2hex(random_bytes(8));
        config()->set('cache.default', 'file');
        config()->set('cache.stores.file.path', $path);
        config()->set('cache.stores.file.lock_path', $path);
        config()->set('queue.default', 'database');
        config()->set('monitoring.spc.automatic_baseline.backlog_limit', 2);
        Cache::purge('file');

        try {
            MonitoredService::factory()->count(6)->create(['check_type' => 'http', 'is_active' => true]);
            $this->assertSame(2, app(AutomaticPhaseOneBaselineService::class)->dispatchDue()['dispatched']);
            app(SystemHealthService::class)->recordBaselineQueueHeartbeat();
            $this->artisan('queue:restart')->assertExitCode(0);

            // A separate cache manager represents another container sharing storage.
            $reader = new CacheManager($this->app);
            $this->assertNotNull($reader->store('file')->get('system-health:baseline-queue:last-heartbeat'));
            $this->assertNotNull($reader->store('file')->get('illuminate:queue:restart'));
            $this->assertSame('healthy', app(SystemHealthService::class)->baselineQueueStatus());
            Cache::flush();
            $this->assertSame(0, app(AutomaticPhaseOneBaselineService::class)->dispatchDue()['dispatched']);
            $this->assertDatabaseCount('jobs', 2);
            $this->assertDatabaseCount('baseline_preparation_runs', 2);
        } finally {
            Cache::purge('file');
            (new Filesystem)->deleteDirectory($path);
        }
    }

    public function test_legacy_pending_and_reserved_jobs_prevent_duplicate_dispatch_without_a_ledger(): void
    {
        config()->set('queue.default', 'database');
        $services = MonitoredService::factory()->count(2)->create(['check_type' => 'http', 'is_active' => true]);
        foreach ($services as $service) {
            Queue::push(new PrepareAutomaticPhaseOneBaseline($service->id, null,
                '2026-11-17T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1'), '', 'baseline-preparation');
        }
        DB::table('jobs')->where('id', DB::table('jobs')->max('id'))->update(['reserved_at' => now()->timestamp]);
        Cache::flush();

        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(2, $counts['duplicate']);
        $this->assertSame(0, $counts['dispatched']);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('baseline_preparation_runs', 0);
    }

    public function test_five_is_a_hard_ceiling_even_if_configuration_is_higher(): void
    {
        Queue::fake();
        config()->set('monitoring.spc.automatic_baseline.maximum_jobs_per_run', 100);
        MonitoredService::factory()->count(20)->create(['check_type' => 'http', 'is_active' => true]);
        $this->assertSame(5, app(AutomaticPhaseOneBaselineService::class)->dispatchDue()['dispatched']);
    }

    public function test_rejected_enqueue_is_a_failure_and_rolls_back_its_ledger(): void
    {
        config()->set('queue.default', 'database');
        MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        Queue::shouldReceive('push')->once()->andReturn(false);

        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['dispatched']);
        $this->assertDatabaseCount('baseline_preparation_runs', 0);
        $this->assertSame(0, DB::table('baseline_preparation_scheduler')->value('last_service_id'));
    }

    public function test_disabled_service_is_skipped_by_an_already_queued_job_and_completed_replay_is_a_noop(): void
    {
        config()->set('queue.default', 'database');
        $service = MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');
        app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        $run = BaselinePreparationRun::sole();
        $job = new PrepareAutomaticPhaseOneBaseline($service->id, null,
            $run->cutoff_at->toIso8601String(), $run->analysis_timezone, $run->policy_version, $run->id);
        $service->update(['is_active' => false]);
        $job->handle(app(AutomaticPhaseOneBaselineService::class));
        $job->handle(app(AutomaticPhaseOneBaselineService::class));

        $this->assertSame(1, $run->fresh()->attempts);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_serialized_pre_upgrade_jobs_without_the_new_readonly_property_remain_executable(): void
    {
        $service = MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');
        $reflection = new \ReflectionClass(PrepareAutomaticPhaseOneBaseline::class);
        $legacy = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'serviceId' => $service->id, 'actorId' => null, 'cutoff' => '2026-11-17T21:00:00+00:00',
            'timezone' => 'Asia/Damascus', 'policyVersion' => 'phase1-automatic-v1',
        ] as $field => $value) {
            $reflection->getProperty($field)->setValue($legacy, $value);
        }
        $payload = serialize($legacy);
        $this->assertStringNotContainsString('preparationRunId', $payload);
        $job = unserialize($payload);
        $job->handle(app(AutomaticPhaseOneBaselineService::class));
        $job->failed(new RuntimeException('Legacy failure callback'));

        $this->assertDatabaseCount('baseline_preparation_runs', 0);
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_failed_enqueue_is_reported_and_does_not_leave_a_phantom_pending_run(): void
    {
        MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => true]);
        config()->set('queue.default', 'database');
        Queue::shouldReceive('push')->once()->andThrow(new RuntimeException('sensitive dispatch detail'));
        Log::spy();

        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['dispatched']);
        $this->assertDatabaseCount('baseline_preparation_runs', 0);
        Log::shouldHaveReceived('error')->once()->with(
            'Automatic Phase I baseline dispatch failed.',
            \Mockery::on(fn (array $context): bool => $context['exception_class'] === RuntimeException::class
                && ! str_contains(json_encode($context), 'sensitive dispatch detail')),
        );
    }
}
