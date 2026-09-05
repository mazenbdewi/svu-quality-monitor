<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemOperationsPage;
use App\Livewire\SystemHealthIndicator;
use App\Models\User;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SystemHealthIndicatorTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertDontSee('fi-badge', false)
            ->assertSee(SystemOperationsPage::getUrl())
            ->assertSee('wire:poll.60s="refreshHealth"', false);
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
