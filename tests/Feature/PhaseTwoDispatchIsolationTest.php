<?php

namespace Tests\Feature;

use App\Enums\MonitoringCheckType;
use App\Events\IncidentConfirmed;
use App\Jobs\EvaluateSpcPhaseTwo;
use App\Models\MonitoredService;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Monitoring\CheckResult;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\ServiceCheckRunner;
use App\Services\Spc\SafePhaseTwoDispatch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class PhaseTwoDispatchIsolationTest extends TestCase
{
    // Real commits in isolated in-memory SQLite, so afterCommit callbacks actually execute.
    use DatabaseMigrations;

    public function test_failed_post_commit_evaluation_cannot_undo_checks_or_incident_state(): void
    {
        $service = MonitoredService::factory()->create(['created_at' => now()->subYear(), 'warning_response_ms' => 100, 'critical_response_ms' => 200, 'failure_confirmation_count' => 1]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        foreach ([100, 110, 130, 160] as $i => $ms) {
            $at = now()->subDays(2)->startOfDay()->addHours($i);
            $c = $service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'is_during_maintenance' => false, 'response_time_ms' => $ms]);
            $c->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
        $engine = app(PhaseOneBaselineService::class);
        $b = $engine->create($service, 'i_chart', now()->subDays(2)->startOfDay()->toIso8601String(), now()->subDays(2)->startOfDay()->addHours(4)->toIso8601String(), 'UTC', $admin);
        $engine->review($b, $admin, 'Reviewed fixture', true);
        $engine->approve($b, $admin, 'Reference for dispatch test');
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) {
            $this->assertInstanceOf(EvaluateSpcPhaseTwo::class, $job);
            $this->assertSame(0, DB::transactionLevel());
            throw new \RuntimeException('Queue unavailable');
        });
        Event::fake([IncidentConfirmed::class]);
        $runner = app(ServiceCheckRunner::class);
        $critical = $runner->persist($service, new CheckResult(true, now(), 200, 250), 'automatic', MonitoringCheckType::Http);
        $this->assertTrue($critical->is_success);
        $this->assertTrue($critical->is_slow);
        $this->assertDatabaseCount('service_incidents', 0);
        $failed = $runner->persist($service, new CheckResult(false, now()->addMinute(), 500, 50, errorType: 'http_error'), 'automatic', MonitoringCheckType::Http);
        $this->assertFalse($failed->is_success);
        $this->assertDatabaseCount('service_checks', 6);
        $this->assertDatabaseCount('service_incidents', 1);
        $this->assertEquals(1, ServiceIncident::first()->failure_count);
    }

    public function test_manual_checks_do_not_dispatch_phase_two(): void
    {
        $dispatch = Mockery::mock(SafePhaseTwoDispatch::class);
        $dispatch->shouldNotReceive('dispatch');
        $this->app->instance(SafePhaseTwoDispatch::class, $dispatch);
        app(ServiceCheckRunner::class)->persist(MonitoredService::factory()->create(), new CheckResult(true, now(), 200, 100), 'manual');
        $this->assertDatabaseCount('service_checks', 1);
    }
}
