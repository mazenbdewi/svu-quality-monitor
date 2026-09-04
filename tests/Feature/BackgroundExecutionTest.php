<?php

namespace Tests\Feature;

use App\Console\Commands\CheckDueMonitoredServices;
use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Services\ServiceCheckRunner;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackgroundExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_job_uses_the_existing_runner_for_an_automatic_check(): void
    {
        $service = MonitoredService::factory()->create(['is_active' => true]);
        $runner = Mockery::mock(ServiceCheckRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn (MonitoredService $record): bool => $record->is($service)), ServiceCheck::SOURCE_AUTOMATIC);

        (new CheckMonitoredServiceJob($service->id))->handle($runner, app(SystemHealthService::class));
    }

    public function test_automatic_job_stores_its_source_without_changing_manual_checks(): void
    {
        Http::fake(['https://automatic.test' => Http::response('OK', 200)]);
        $service = MonitoredService::factory()->create([
            'url' => 'https://automatic.test',
            'expected_keyword' => null,
            'is_active' => true,
        ]);

        (new CheckMonitoredServiceJob($service->id))->handle(
            app(ServiceCheckRunner::class),
            app(SystemHealthService::class),
        );

        $this->assertDatabaseHas('service_checks', [
            'monitored_service_id' => $service->id,
            'source' => ServiceCheck::SOURCE_AUTOMATIC,
        ]);
    }

    public function test_failed_dispatch_for_one_service_does_not_stop_other_due_services(): void
    {
        $first = MonitoredService::factory()->create(['is_active' => true]);
        $second = MonitoredService::factory()->create(['is_active' => true]);
        $dispatcher = Mockery::mock(Dispatcher::class);

        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (CheckMonitoredServiceJob $job): bool => $job->monitoredServiceId === $first->id))
            ->andThrow(new RuntimeException('Queue unavailable'));
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (CheckMonitoredServiceJob $job): bool => $job->monitoredServiceId === $second->id))
            ->andReturn(null);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan(CheckDueMonitoredServices::class)
            ->expectsOutput('Due services count: 2')
            ->expectsOutput('Dispatched services count: 1')
            ->expectsOutput('Failed dispatches count: 1')
            ->assertSuccessful();
    }

    public function test_unique_job_lock_rejects_a_duplicate_service_check_until_released(): void
    {
        $service = MonitoredService::factory()->create();
        $first = new CheckMonitoredServiceJob($service->id);
        $duplicate = new CheckMonitoredServiceJob($service->id);
        $lock = new UniqueLock(Cache::store());

        $this->assertTrue($lock->acquire($first));
        $this->assertFalse($lock->acquire($duplicate));

        $lock->release($first);

        $this->assertTrue($lock->acquire($duplicate));
        $lock->release($duplicate);
    }

    public function test_scheduler_heartbeat_status_transitions_from_healthy_to_warning_to_down(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-08-31 12:00:00');
        $health = app(SystemHealthService::class);

        $health->recordSchedulerHeartbeat();
        $this->assertSame('healthy', $health->schedulerStatus());

        Carbon::setTestNow('2026-08-31 12:03:01');
        $this->assertSame('warning', $health->schedulerStatus());

        Carbon::setTestNow('2026-08-31 12:05:01');
        $this->assertSame('down', $health->schedulerStatus());
    }

    public function test_queue_heartbeat_and_job_activity_are_reported_without_pending_jobs(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-08-31 12:00:00');
        $health = app(SystemHealthService::class);

        $health->recordQueueHeartbeat();
        $health->recordQueueJobSucceeded();
        $health->recordQueueJobFailed();
        $snapshot = $health->snapshot();

        $this->assertSame('healthy', $snapshot['queue']['status']);
        $this->assertNotNull($snapshot['queue']['last_success']);
        $this->assertNotNull($snapshot['queue']['last_failure']);
        $this->assertNull($snapshot['queue']['pending_jobs']);

        Carbon::setTestNow('2026-08-31 12:03:01');
        $this->assertSame('down', $health->queueStatus());
    }

    public function test_job_failure_records_safe_queue_activity_without_logging_the_exception_message(): void
    {
        Cache::flush();
        Log::spy();
        $service = MonitoredService::factory()->create();

        (new CheckMonitoredServiceJob($service->id))->failed(new RuntimeException('sensitive token value'));

        $this->assertNotNull(app(SystemHealthService::class)->snapshot()['queue']['last_failure']);
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Automatic service-check job failed.', Mockery::on(function (array $context) use ($service): bool {
                return $context['monitored_service_id'] === $service->id
                    && $context['exception_class'] === RuntimeException::class
                    && ! array_key_exists('message', $context);
            }));
    }

    public function test_operations_snapshot_uses_automatic_checks_not_manual_checks_for_monitoring_activity(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        $service = MonitoredService::factory()->create(['is_active' => true, 'check_interval_minutes' => 15]);
        $automatic = $service->serviceChecks()->create([
            'checked_at' => now()->subMinutes(20),
            'source' => ServiceCheck::SOURCE_AUTOMATIC,
            'status_code' => 200,
            'response_time_ms' => 100,
            'is_success' => true,
            'is_slow' => false,
        ]);
        $service->serviceChecks()->create([
            'checked_at' => now()->subMinute(),
            'source' => ServiceCheck::SOURCE_MANUAL,
            'status_code' => 200,
            'response_time_ms' => 100,
            'is_success' => true,
            'is_slow' => false,
        ]);

        $snapshot = app(SystemHealthService::class)->snapshot();

        $this->assertTrue($snapshot['monitoring']['last_automatic_success']->equalTo($automatic->checked_at));
        $this->assertSame(1, $snapshot['monitoring']['active_services']);
        $this->assertSame(0, $snapshot['monitoring']['due_services']);
    }
}
