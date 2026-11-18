<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemOperationsPage;
use App\Livewire\SystemHealthIndicator;
use App\Models\BaselinePreparationRun;
use App\Models\MonitoredService;
use App\Models\SystemHealthAlertState;
use App\Models\User;
use App\Services\BackgroundHealthAlertService;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SystemHealthIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_slo_only_worker_cannot_mask_a_stopped_monitoring_worker(): void
    {
        Cache::flush();
        Event::dispatch(new Looping('database', 'slo-calibration'));
        $this->assertFalse(Cache::has('system-health:queue:last-heartbeat'));
        $this->assertTrue(Cache::has('system-health:slo-queue:last-heartbeat'));

        $sloJob = Mockery::mock(QueueJob::class);
        $sloJob->shouldReceive('getQueue')->twice()->andReturn('slo-calibration');
        Event::dispatch(new JobProcessed('database', $sloJob));
        Event::dispatch(new JobFailed('database', $sloJob, new RuntimeException('synthetic')));
        $this->assertFalse(Cache::has('system-health:queue:last-success'));
        $this->assertFalse(Cache::has('system-health:queue:last-failure'));

        Event::dispatch(new Looping('database', 'default'));
        $this->assertTrue(Cache::has('system-health:queue:last-heartbeat'));
    }

    public function test_baseline_worker_heartbeat_is_independent_and_idle_safe(): void
    {
        Cache::flush();
        Event::dispatch(new Looping('database', 'baseline-preparation'));

        $this->assertTrue(Cache::has('system-health:baseline-queue:last-heartbeat'));
        $this->assertFalse(Cache::has('system-health:queue:last-heartbeat'));
        config()->set('queue.default', 'database');
        $this->assertSame('healthy', app(SystemHealthService::class)->baselineQueueSnapshot()['status']);
    }

    public function test_baseline_queue_reports_backlog_processing_failures_age_and_last_success(): void
    {
        config()->set('queue.default', 'database');
        config()->set('monitoring.spc.automatic_baseline.oldest_pending_warning_seconds', 60);
        app(SystemHealthService::class)->recordBaselineQueueHeartbeat();
        DB::table('jobs')->insert([
            ['queue' => 'baseline-preparation', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(5)->timestamp],
            ['queue' => 'baseline-preparation', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->subMinute()->timestamp],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-baseline', 'connection' => 'database', 'queue' => 'baseline-preparation',
            'payload' => '{}', 'exception' => 'synthetic', 'failed_at' => now(),
        ]);
        $service = MonitoredService::factory()->create();
        BaselinePreparationRun::create([
            'monitored_service_id' => $service->id, 'policy_version' => 'phase1-automatic-v1',
            'cutoff_at' => now()->startOfDay(), 'analysis_timezone' => 'Asia/Damascus',
            'status' => 'completed', 'created_drafts' => 3, 'dispatched_at' => now()->subMinute(), 'completed_at' => now(),
        ]);

        $snapshot = app(SystemHealthService::class)->baselineQueueSnapshot();

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame(1, $snapshot['pending_jobs']);
        $this->assertSame(1, $snapshot['processing_jobs']);
        $this->assertSame(1, $snapshot['failed_jobs']);
        $this->assertGreaterThanOrEqual(300, $snapshot['oldest_pending_seconds']);
        $this->assertNotNull($snapshot['last_successful_preparation']);
    }

    public function test_baseline_worker_startup_grace_then_unavailable_and_recovery(): void
    {
        config()->set('queue.default', 'database');
        Cache::flush();
        Carbon::setTestNow('2026-11-18 12:00:00 UTC');
        $health = app(SystemHealthService::class);
        $health->expectBaselineQueue();
        $this->assertSame('warning', $health->baselineQueueStatus());

        Carbon::setTestNow(now()->addSeconds(config('monitoring.spc.automatic_baseline.startup_grace_seconds') + 1));
        $this->assertSame('unavailable', $health->baselineQueueStatus());
        $health->recordBaselineQueueHeartbeat();
        $this->assertSame('healthy', $health->baselineQueueStatus());
    }

    public function test_startup_grace_suppresses_alert_but_excessive_backlog_alerts_and_recovers(): void
    {
        config()->set('queue.default', 'database');
        config()->set('monitoring.spc.automatic_baseline.oldest_pending_warning_seconds', 60);
        Cache::flush();
        $health = app(SystemHealthService::class);
        $health->expectBaselineQueue();
        app(BackgroundHealthAlertService::class)->check();
        $this->assertDatabaseMissing('system_health_alert_states', ['component' => 'baseline_preparation']);

        $health->recordBaselineQueueHeartbeat();
        DB::table('jobs')->insert([
            'queue' => 'baseline-preparation', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(5)->timestamp,
        ]);
        app(BackgroundHealthAlertService::class)->check();
        $this->assertSame('down', SystemHealthAlertState::where('component', 'baseline_preparation')->sole()->status);

        DB::table('jobs')->where('queue', 'baseline-preparation')->delete();
        app(BackgroundHealthAlertService::class)->check();
        $this->assertSame('healthy', SystemHealthAlertState::where('component', 'baseline_preparation')->sole()->status);
    }

    public function test_slo_queue_status_exposes_stopped_worker_and_pending_backlog_without_masking_monitoring(): void
    {
        config()->set('monitoring.slo.automatic_preparation_enabled', true);
        config()->set('queue.default', 'database');
        Cache::flush();
        DB::table('jobs')->insert([
            'queue' => 'slo-calibration', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->subMinutes(5)->timestamp,
        ]);

        $snapshot = app(SystemHealthService::class)->sloQueueSnapshot();
        $this->assertSame('down', $snapshot['status']);
        $this->assertSame(1, $snapshot['pending_jobs']);
        $this->assertGreaterThanOrEqual(300, $snapshot['oldest_pending_seconds']);
        $this->assertSame('down', app(SystemHealthService::class)->queueStatus());

        Event::dispatch(new Looping('database', 'slo-calibration'));
        $this->assertSame('healthy', app(SystemHealthService::class)->sloQueueSnapshot()['status']);
        $this->assertSame('down', app(SystemHealthService::class)->queueStatus());

        config()->set('monitoring.slo.automatic_preparation_enabled', false);
        $this->assertSame('disabled', app(SystemHealthService::class)->sloQueueSnapshot()['status']);
    }

    public function test_operations_page_labels_slo_worker_state_in_english_and_arabic(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('operator');
        $this->actingAs($operator);
        config()->set('queue.default', 'database');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            config()->set('monitoring.slo.automatic_preparation_enabled', false);
            $this->get(SystemOperationsPage::getUrl())->assertOk()
                ->assertSee(__('monitoring.operations.health.slo_queue.title'))
                ->assertSee(__('monitoring.operations.health.baseline_queue.title'))
                ->assertSee(__('monitoring.operations.health.baseline_queue.pending_jobs'))
                ->assertSee(__('monitoring.operations.health.statuses.disabled'));

            config()->set('monitoring.slo.automatic_preparation_enabled', true);
            Cache::forget('system-health:slo-queue:last-heartbeat');
            $this->get(SystemOperationsPage::getUrl())->assertOk()
                ->assertSee(__('monitoring.operations.health.slo_queue.pending_jobs'))
                ->assertSee(__('monitoring.operations.health.statuses.down'));
        }
    }

    public function test_monitoring_heartbeat_follows_a_custom_default_queue_name(): void
    {
        config()->set('queue.connections.database.queue', 'monitoring-primary');
        Cache::flush();
        Event::dispatch(new Looping('database', 'slo-calibration'));
        Event::dispatch(new Looping('database', 'default'));
        $this->assertFalse(Cache::has('system-health:queue:last-heartbeat'));

        Event::dispatch(new Looping('database', 'monitoring-primary'));
        $this->assertTrue(Cache::has('system-health:queue:last-heartbeat'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scheduler_healthy_heartbeat_is_shown_as_healthy(): void
    {
        $this->recordSchedulerHeartbeatAt('2026-09-01 12:00:00');

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.scheduler.status', 'healthy')
            ->assertSee(__('monitoring.operations.health.indicator.scheduler.healthy'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #22c55e', false);
    }

    public function test_scheduler_delayed_heartbeat_is_shown_as_warning(): void
    {
        $this->recordSchedulerHeartbeatAt('2026-09-01 12:00:00');
        Carbon::setTestNow('2026-09-01 12:03:01');

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.scheduler.status', 'warning')
            ->assertSee(__('monitoring.operations.health.indicator.scheduler.warning'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #f59e0b', false);
    }

    public function test_missing_scheduler_heartbeat_is_shown_as_down(): void
    {
        Cache::flush();

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.scheduler.status', 'down')
            ->assertSee(__('monitoring.operations.health.indicator.scheduler.down'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #ef4444', false);
    }

    public function test_queue_healthy_heartbeat_is_shown_as_healthy(): void
    {
        $this->recordQueueHeartbeatAt('2026-09-01 12:00:00');

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.queue.status', 'healthy')
            ->assertSee(__('monitoring.operations.health.indicator.queue.healthy'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #22c55e', false);
    }

    public function test_queue_delayed_heartbeat_is_shown_as_warning(): void
    {
        $this->recordQueueHeartbeatAt('2026-09-01 12:00:00');
        Carbon::setTestNow('2026-09-01 12:01:31');

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.queue.status', 'warning')
            ->assertSee(__('monitoring.operations.health.indicator.queue.warning'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #f59e0b', false);
    }

    public function test_missing_queue_heartbeat_is_shown_as_down(): void
    {
        Cache::flush();

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.queue.status', 'down')
            ->assertSee(__('monitoring.operations.health.indicator.queue.down'))
            ->assertSee('width: 16px; height: 16px', false)
            ->assertSee('background-color: #ef4444', false);
    }

    public function test_indicator_uses_the_system_health_service_snapshot_without_duplicating_status_rules(): void
    {
        $snapshot = [
            'scheduler' => [
                'status' => 'warning',
                'last_heartbeat' => now(),
                'expected_interval_seconds' => 60,
            ],
            'queue' => [
                'status' => 'down',
                'last_heartbeat' => null,
                'last_success' => null,
                'last_failure' => null,
                'pending_jobs' => 3,
                'failed_jobs' => 1,
            ],
        ];
        $systemHealth = Mockery::mock(SystemHealthService::class);
        $systemHealth->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $systemHealth->shouldReceive('baselineQueueSnapshot')->once()->andReturn(['status' => 'warning']);
        $this->app->instance(SystemHealthService::class, $systemHealth);

        Livewire::test(SystemHealthIndicator::class)
            ->assertSet('health.scheduler.status', 'warning')
            ->assertSet('health.queue.status', 'down')
            ->assertSee(__('monitoring.operations.health.indicator.scheduler.warning'))
            ->assertSee(__('monitoring.operations.health.indicator.queue.down'));
    }

    public function test_indicator_is_rendered_in_the_topbar_with_a_minute_polling_interval(): void
    {
        config(['app.env' => 'local']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('operator');
        $this->actingAs($user)
            ->get(SystemOperationsPage::getUrl())
            ->assertOk()
            ->assertSee('data-system-health-indicator="scheduler"', false)
            ->assertSee('data-system-health-indicator="queue"', false)
            ->assertSee('aria-label="'.e(__('monitoring.operations.health.indicator.scheduler.down')).'"', false)
            ->assertSee('aria-label="'.e(__('monitoring.operations.health.indicator.queue.down')).'"', false)
            ->assertSee(SystemOperationsPage::getUrl())
            ->assertSee('wire:poll.60s="refreshHealth"', false);

        // Status badges elsewhere on the operations page do not change the topbar dots.
        Livewire::test(SystemHealthIndicator::class)->assertDontSee('fi-badge', false);
    }

    public function test_indicator_statuses_are_translated_in_english_and_arabic(): void
    {
        $translations = [
            'en' => [
                'scheduler.healthy' => 'Automatic checks are running',
                'scheduler.warning' => 'Automatic checks are delayed',
                'scheduler.down' => 'Automatic checks are stopped',
                'queue.healthy' => 'Background processing is running',
                'queue.warning' => 'Background processing is delayed',
                'queue.down' => 'Background processing is stopped',
            ],
            'ar' => [
                'scheduler.healthy' => 'الفحص التلقائي يعمل',
                'scheduler.warning' => 'الفحص التلقائي متأخر',
                'scheduler.down' => 'الفحص التلقائي متوقف',
                'queue.healthy' => 'المعالجة الخلفية تعمل',
                'queue.warning' => 'المعالجة الخلفية متأخرة',
                'queue.down' => 'المعالجة الخلفية متوقفة',
            ],
        ];

        foreach ($translations as $locale => $labels) {
            foreach ($labels as $status => $label) {
                $this->assertSame($label, trans('monitoring.operations.health.indicator.'.$status, [], $locale));
            }
        }
    }

    private function recordSchedulerHeartbeatAt(string $time): void
    {
        Cache::flush();
        Carbon::setTestNow($time);

        app(SystemHealthService::class)->recordSchedulerHeartbeat();
    }

    private function recordQueueHeartbeatAt(string $time): void
    {
        Cache::flush();
        Carbon::setTestNow($time);

        app(SystemHealthService::class)->recordQueueHeartbeat();
    }
}
