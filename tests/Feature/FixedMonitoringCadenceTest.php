<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\MonitoringOpportunity;
use App\Models\MonitoringScheduleEpoch;
use App\Models\ServiceCheck;
use App\Monitoring\CheckResult;
use App\Services\FixedMonitoringCadence;
use App\Services\ServiceCheckRunner;
use App\Services\SloCalibrationAnalysis;
use App\Services\SystemHealthService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FixedMonitoringCadenceTest extends TestCase
{
    use RefreshDatabase;

    private function enableAt(string $cutover = '2026-11-17T00:00:00Z'): void
    {
        config()->set('queue.default', 'database');
        config()->set('monitoring.fixed_cadence.enabled', true);
        config()->set('monitoring.fixed_cadence.cutover_utc', $cutover);
        Http::fake(['https://fixed-cadence.test/*' => Http::response('OK', 200)]);
    }

    public static function intervals(): array
    {
        return ['one minute' => [1], 'five minutes' => [5], 'fifteen minutes' => [15]];
    }

    #[DataProvider('intervals')]
    public function test_nominal_cadence_is_not_shifted_by_worker_start_or_manual_checks(int $interval): void
    {
        $origin = Carbon::parse('2026-11-17T00:00:00Z');
        $this->travelTo($origin);
        $this->enableAt();
        $service = MonitoredService::factory()->create([
            'url' => "https://fixed-cadence.test/{$interval}", 'expected_keyword' => null,
            'check_interval_minutes' => $interval, 'is_active' => true,
        ]);

        for ($slot = 0; $slot < 3; $slot++) {
            $this->travelTo($origin->copy()->addMinutes($slot * $interval));
            $this->artisan('services:check-due')->assertSuccessful();
            $this->artisan('services:check-due')->assertSuccessful();
            $this->assertSame(1, DB::table('jobs')->count());
            if ($slot === 1) {
                $service->serviceChecks()->create([
                    'checked_at' => now()->addSeconds(2), 'source' => ServiceCheck::SOURCE_MANUAL,
                    'check_type' => 'http', 'is_success' => true, 'is_slow' => false,
                    'is_during_maintenance' => false,
                ]);
            }
            $this->travelTo(now()->addSeconds(5));
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])->assertSuccessful();
        }

        $opportunities = MonitoringOpportunity::query()->orderBy('nominal_due_at_utc')->get();
        $this->assertCount(3, $opportunities);
        $this->assertSame(['completed', 'completed', 'completed'], $opportunities->pluck('status')->all());
        $this->assertSame([0, $interval, $interval * 2], $opportunities->map(fn ($opportunity) => (int) $origin->diffInMinutes($opportunity->nominal_due_at_utc))->all());
        $this->assertSame(3, $service->serviceChecks()->where('source', ServiceCheck::SOURCE_AUTOMATIC)->count());
        $this->assertSame(1, MonitoringScheduleEpoch::query()->count());
    }

    public function test_delayed_worker_does_not_shift_slots_and_pending_slots_are_missed(): void
    {
        $origin = Carbon::parse('2026-11-17T00:00:00Z');
        $this->travelTo($origin);
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/delay', 'expected_keyword' => null, 'check_interval_minutes' => 1]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->travelTo($origin->copy()->addMinutes(1));
        $this->artisan('services:check-due')->assertSuccessful();
        $this->travelTo($origin->copy()->addMinutes(2));
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(['queued', 'missed', 'missed'], MonitoringOpportunity::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, $service->serviceChecks()->count());
        $this->travelTo($origin->copy()->addMinutes(2)->addSeconds(5));
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertSame('completed', MonitoringOpportunity::query()->first()->status);
        $this->travelTo($origin->copy()->addMinutes(3));
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame('queued', MonitoringOpportunity::query()->orderByDesc('id')->first()->status);
    }

    public function test_disabled_service_and_configuration_change_close_epochs_without_rewriting_checks(): void
    {
        $origin = Carbon::parse('2026-11-17T00:00:00Z');
        $this->travelTo($origin);
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/change', 'expected_keyword' => null, 'check_interval_minutes' => 5]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->travelTo($origin->copy()->addMinutes(2));
        $service->update(['is_active' => false]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame('cancelled', MonitoringOpportunity::query()->first()->status);
        $this->travelTo($origin->copy()->addMinutes(7));
        $service->update(['is_active' => true, 'check_interval_minutes' => 1]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame(2, MonitoringScheduleEpoch::query()->count());
        $this->assertSame([5, 1], MonitoringScheduleEpoch::query()->orderBy('id')->pluck('interval_minutes')->all());
        $this->assertSame(0, $service->serviceChecks()->count());
    }

    public function test_failed_dispatch_rolls_back_opportunity_and_cursor(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/error', 'expected_keyword' => null]);
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $this->expectException(RuntimeException::class);
        try {
            app(FixedMonitoringCadence::class)->process($service, $dispatcher);
        } finally {
            $this->assertDatabaseCount('monitoring_opportunities', 0);
            $this->assertDatabaseCount('monitoring_schedule_epochs', 0);
        }
    }

    public function test_replayed_job_does_not_create_second_check(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/replay', 'expected_keyword' => null]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        $job = new CheckMonitoredServiceJob($service->id, $opportunity->id);
        $job->handle(app(ServiceCheckRunner::class), app(SystemHealthService::class));
        $job->handle(app(ServiceCheckRunner::class), app(SystemHealthService::class));
        $this->assertSame(1, $service->serviceChecks()->count());
        $this->assertSame('completed', $opportunity->fresh()->status);
    }

    public function test_failed_execution_retries_same_opportunity_without_partial_check(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/retry', 'expected_keyword' => null]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        $job = new CheckMonitoredServiceJob($service->id, $opportunity->id);
        $runner = Mockery::mock(ServiceCheckRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('transient persistence error'));
        try {
            $job->handle($runner, app(SystemHealthService::class));
            $this->fail('The first execution must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('transient persistence error', $exception->getMessage());
        }
        $this->assertSame('queued', $opportunity->fresh()->status);
        $this->assertSame(0, $service->serviceChecks()->count());
        $job->handle(app(ServiceCheckRunner::class), app(SystemHealthService::class));
        $this->assertSame('completed', $opportunity->fresh()->status);
        $this->assertSame(2, $opportunity->fresh()->attempts);
        $this->assertSame(1, $service->serviceChecks()->count());
    }

    public function test_stale_failure_callback_does_not_overwrite_a_recovered_worker_claim(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/stale-failure', 'expected_keyword' => null]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        $job = new CheckMonitoredServiceJob($service->id, $opportunity->id);
        $runner = Mockery::mock(ServiceCheckRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($opportunity): void {
            $opportunity->fresh()->update([
                'status' => 'running',
                'claim_token' => '22222222-2222-4222-8222-222222222222',
                'started_at_utc' => now(),
            ]);
            throw new RuntimeException('stale worker failed after recovery');
        });

        try {
            $job->handle($runner, app(SystemHealthService::class));
            $this->fail('The stale worker must fail.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame('running', $opportunity->fresh()->status);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $opportunity->fresh()->claim_token);
        $this->assertSame(0, $service->serviceChecks()->count());
    }

    public function test_stale_worker_cannot_persist_after_recovery_or_after_completion(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/stale-result', 'expected_keyword' => null]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        $opportunity->update([
            'status' => 'running',
            'claim_token' => '22222222-2222-4222-8222-222222222222',
            'started_at_utc' => now(),
        ]);
        $runner = app(ServiceCheckRunner::class);
        $result = new CheckResult(true, now(), 200, 10);

        try {
            $runner->persist($service, $result, ServiceCheck::SOURCE_AUTOMATIC, opportunityId: $opportunity->id, claimToken: '11111111-1111-4111-8111-111111111111');
            $this->fail('The stale claim must not persist.');
        } catch (\LogicException) {
            $this->assertSame(0, $service->serviceChecks()->count());
        }

        $runner->persist($service, $result, ServiceCheck::SOURCE_AUTOMATIC, opportunityId: $opportunity->id, claimToken: '22222222-2222-4222-8222-222222222222');
        $this->assertSame(1, $service->serviceChecks()->count());
        try {
            $runner->persist($service, $result, ServiceCheck::SOURCE_AUTOMATIC, opportunityId: $opportunity->id, claimToken: '11111111-1111-4111-8111-111111111111');
            $this->fail('The stale claim must not persist after completion.');
        } catch (\LogicException) {
            $this->assertSame(1, $service->serviceChecks()->count());
        }
    }

    public function test_disabled_policy_cancels_pending_job_before_any_measurement(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/disabled', 'expected_keyword' => null]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        config()->set('monitoring.fixed_cadence.enabled', false);
        (new CheckMonitoredServiceJob($service->id, $opportunity->id))->handle(app(ServiceCheckRunner::class), app(SystemHealthService::class));
        $this->assertSame('cancelled', $opportunity->fresh()->status);
        $this->assertSame(0, $service->serviceChecks()->count());
    }

    public function test_maintenance_is_recorded_but_does_not_move_nominal_slots(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/maintenance', 'expected_keyword' => null]);
        MaintenanceWindow::query()->create([
            'name' => 'Planned', 'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinute(), 'applies_to_all_services' => true,
        ]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->travelTo(now()->addSeconds(5));
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertSame(1, MonitoringOpportunity::query()->count());
        $this->assertTrue($service->serviceChecks()->firstOrFail()->is_during_maintenance);
        $coverage = app(FixedMonitoringCadence::class)->opportunityCoverage($service, Carbon::parse('2026-11-17T00:00:00Z'), now()->copy()->addMinute());
        $this->assertSame(1, $coverage['due']);
        $this->assertSame(100.0, $coverage['completion_percent']);
        $this->assertSame('fixed_utc_slot_ledger_v1', $coverage['basis']);
    }

    public function test_cross_cutover_calibration_is_incompatible_without_changing_prior_data(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/science', 'expected_keyword' => null]);
        $cadence = app(FixedMonitoringCadence::class);
        $this->assertTrue($cadence->crossesRegime($service, now()->copy()->subDay(), now()->copy()->addDay()));
        $this->assertTrue($cadence->referenceIncompatible($service, now()->copy()->subMonth(), now()->copy()->subDay()));
        $this->assertFalse($cadence->crossesRegime($service, now(), now()->copy()->addDay()));
        $this->assertSame(0, $service->serviceChecks()->count());
    }

    public function test_retroactive_initial_cutover_does_not_invent_missing_opportunities(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16T23:59:00Z'));
        $this->enableAt();
        MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/retroactive', 'expected_keyword' => null]);
        $this->travelTo(Carbon::parse('2026-11-17T00:03:00Z'));
        $this->artisan('services:check-due')->assertFailed();
        $this->assertDatabaseCount('monitoring_opportunities', 0);
        $this->assertDatabaseCount('monitoring_schedule_epochs', 0);
    }

    public function test_unsupported_queue_topology_fails_before_creating_a_slot(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/queue', 'expected_keyword' => null]);
        config()->set('queue.default', 'sync');
        $this->expectException(\LogicException::class);
        try {
            $this->artisan('services:check-due');
        } finally {
            $this->assertDatabaseCount('monitoring_opportunities', 0);
        }
    }

    public function test_new_slo_calibration_labels_cross_regime_as_insufficient(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17T00:00:00Z'));
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/slo', 'expected_keyword' => null]);
        $proposal = app(SloCalibrationAnalysis::class)->propose($service);
        $this->assertSame('insufficient_evidence', $proposal['status']);
        $this->assertContains('monitoring_sampling_regime_obsolete_at_preparation', $proposal['warnings']);
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_terminal_worker_failure_is_visible_and_does_not_block_the_next_nominal_slot(): void
    {
        $origin = Carbon::parse('2026-11-17T00:00:00Z');
        $this->travelTo($origin);
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/failure', 'expected_keyword' => null, 'check_interval_minutes' => 1]);
        $this->artisan('services:check-due')->assertSuccessful();
        $first = MonitoringOpportunity::query()->firstOrFail();
        (new CheckMonitoredServiceJob($service->id, $first->id))->failed(new RuntimeException('test failure'));
        $this->assertSame('failed', $first->fresh()->status);
        $this->travelTo($origin->copy()->addMinute());
        // A new command instance after a scheduler restart uses the durable cursor.
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame(['failed', 'queued'], MonitoringOpportunity::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, $service->serviceChecks()->count());
    }

    public function test_configuration_change_cancels_an_in_flight_opportunity_before_result_persistence(): void
    {
        $origin = Carbon::parse('2026-11-17T00:00:00Z');
        $this->travelTo($origin);
        $this->enableAt();
        $service = MonitoredService::factory()->create(['url' => 'https://fixed-cadence.test/inflight', 'expected_keyword' => null, 'check_interval_minutes' => 1]);
        $this->artisan('services:check-due')->assertSuccessful();
        $opportunity = MonitoringOpportunity::query()->firstOrFail();
        $opportunity->update(['status' => 'running', 'claim_token' => '11111111-1111-4111-8111-111111111111', 'started_at_utc' => now()]);
        $this->travelTo($origin->copy()->addSeconds(30));
        $service->update(['expected_status_code' => 204]);
        $this->artisan('services:check-due')->assertSuccessful();
        $this->assertSame('cancelled', $opportunity->fresh()->status);
        $this->assertSame(0, $service->serviceChecks()->count());
    }
}
