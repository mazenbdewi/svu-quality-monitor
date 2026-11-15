<?php

namespace Tests\Feature;

use App\Jobs\PrepareAutomaticSloProposal;
use App\Models\AuditLog;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SloProposal;
use App\Models\SpcSignal;
use App\Models\User;
use App\Services\EligibleSyntheticChecks;
use App\Services\SloAutomaticPreparation;
use App\Services\SloCalibrationAnalysis;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AutomaticSloPreparationTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->service = MonitoredService::factory()->create([
            'check_type' => 'http', 'is_active' => true, 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440, 'warning_response_ms' => 2500, 'critical_response_ms' => 500,
        ]);
    }

    public function test_scheduler_uses_institution_local_daily_time_and_dispatches_one_bounded_job(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'slo:prepare-due'));
        $this->assertNotNull($event);
        $this->assertSame('35 0 * * *', $event->expression);
        $this->assertSame('Asia/Damascus', $event->timezone);

        $this->calibrationChecks();
        MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => false]);
        MonitoredService::factory()->create(['check_type' => 'tcp', 'is_active' => true]);
        Bus::fake();
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 1);
        Bus::assertDispatched(PrepareAutomaticSloProposal::class, function (PrepareAutomaticSloProposal $job): bool {
            return $job->serviceId === $this->service->id
                && $job->cutoff === '2026-11-14T21:00:00+00:00'
                && $job->timezone === 'Asia/Damascus'
                && $job->policyVersion === SloCalibrationAnalysis::POLICY_VERSION
                && $job->configurationFingerprint === app(SloCalibrationAnalysis::class)->configurationFingerprint($this->service, 'Asia/Damascus')
                && $job->timeout < 60;
        });
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 1);
        $this->assertTrue(Cache::has(SloAutomaticPreparation::statusKey($this->service->id)));
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_job_creates_only_one_frozen_provisional_draft_and_preserves_research_data(): void
    {
        $this->calibrationChecks();
        $before = ServiceCheck::query()->orderBy('id')->get()->toArray();
        $job = $this->job();
        $job->handle(app(SloAutomaticPreparation::class));
        $job->handle(app(SloAutomaticPreparation::class));
        $this->assertDatabaseCount('slo_proposals', 1);
        $proposal = SloProposal::query()->firstOrFail();
        $this->assertNull($proposal->created_by);
        $this->assertSame('draft', $proposal->status);
        $this->assertSame('provisional_research_only', $proposal->calibration_payload['status']);
        $this->assertSame(28, $proposal->calibration_payload['latency_observations']);
        $this->assertSame(2800, $proposal->proposed_latency_ms);
        $this->assertContains('p99_tail_uncertainty_not_quantified', $proposal->calibration_payload['warnings']);
        $this->assertSame('scheduled_system', AuditLog::where('event', 'slo.proposal.created')->firstOrFail()->context['initiator']);
        $this->assertSame($before, ServiceCheck::query()->orderBy('id')->get()->toArray());
        $this->assertSame(2500, $this->service->fresh()->warning_response_ms);
        $this->assertSame(500, $this->service->fresh()->critical_response_ms);
        $this->assertSame(0, ControlChartBaseline::count() + SpcSignal::count());
    }

    public function test_insufficient_samples_or_coverage_never_create_a_proposal(): void
    {
        Bus::fake();
        $this->check(Carbon::parse('2026-11-01 00:00:00', 'UTC'), 100);
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertNotDispatched(PrepareAutomaticSloProposal::class);

        for ($index = 1; $index < 20; $index++) {
            $this->check(Carbon::parse('2026-11-01 00:00:00', 'UTC')->addMinutes($index), 100);
        }
        $this->job()->handle(app(SloAutomaticPreparation::class));
        $this->assertDatabaseCount('slo_proposals', 0);
        $this->assertSame(0, AuditLog::where('event', 'slo.proposal.created')->count());
    }

    public function test_manual_maintenance_and_failed_checks_cannot_satisfy_preparation_gate(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $at = Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day);
            $this->check($at, 100, source: ServiceCheck::SOURCE_MANUAL);
            $this->check($at->copy()->addMinutes(1), 100, maintenance: true);
            $this->check($at->copy()->addMinutes(2), 100, success: false);
        }
        Bus::fake();
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertNotDispatched(PrepareAutomaticSloProposal::class);
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_automatic_proposal_preserves_unattainable_target_warning_without_raising_latency_target(): void
    {
        $this->calibrationChecks();
        $this->check(Carbon::parse('2026-11-01 00:10:00', 'UTC'), 100, success: false);
        $this->job()->handle(app(SloAutomaticPreparation::class));
        $proposal = SloProposal::query()->firstOrFail();
        $this->assertSame(2800, $proposal->proposed_latency_ms);
        $this->assertSame('99.00', $proposal->proposed_compliance_percent);
        $this->assertContains('combined_target_unattainable_given_calibration_availability', $proposal->calibration_payload['warnings']);
        $this->assertContains('in_sample_combined_below_proposed_target', $proposal->calibration_payload['warnings']);
    }

    public function test_prior_draft_reviewed_approved_or_retired_decisions_block_automatic_recalibration(): void
    {
        $this->calibrationChecks();
        $admin = User::factory()->create(['is_active' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin->assignRole('administrator');
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $admin);
        $original = $proposal->payload_digest;

        foreach (['draft', 'reviewed', 'approved', 'retired'] as $status) {
            if ($status === 'reviewed') {
                $proposal = $manager->review($proposal, $admin, 'Scientific limits acknowledged.', true);
            } elseif ($status === 'approved') {
                $proposal = $manager->approve($proposal, $admin, 'Research only.');
            } elseif ($status === 'retired') {
                $proposal = $manager->retire($proposal, $admin, 'Historical objective closed.');
            }
            $this->assertNull($this->job()->handle(app(SloAutomaticPreparation::class)));
            Bus::fake();
            $this->artisan('slo:prepare-due')->assertSuccessful();
            Bus::assertNotDispatched(PrepareAutomaticSloProposal::class);
            $this->assertSame($status, $proposal->fresh()->status);
            $this->assertSame($original, $proposal->fresh()->payload_digest);
            $this->assertDatabaseCount('slo_proposals', 1);
        }
    }

    public function test_stale_cutoff_and_changed_configuration_do_not_recalibrate_or_write(): void
    {
        $this->calibrationChecks();
        $this->assertNull(app(SloAutomaticPreparation::class)->prepareOne($this->service->id,
            '2026-11-13T21:00:00+00:00', 'Asia/Damascus', SloCalibrationAnalysis::POLICY_VERSION, $this->fingerprint()));
        $this->assertNull(app(SloAutomaticPreparation::class)->prepareOne($this->service->id,
            '2026-11-14T21:00:00+00:00', 'UTC', SloCalibrationAnalysis::POLICY_VERSION, $this->fingerprint()));
        $queued = $this->job();
        $this->service->update(['check_interval_minutes' => 1]);
        $queued->handle(app(SloAutomaticPreparation::class));
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_job_failure_retains_preparing_state_for_retry_then_clears_it_on_terminal_failure(): void
    {
        $key = SloAutomaticPreparation::statusKey($this->service->id);
        Cache::put($key, 'pending', 120);
        $preparation = $this->mock(SloAutomaticPreparation::class);
        $preparation->shouldReceive('prepareOne')->once()->andThrow(new RuntimeException('retry'));
        try {
            $this->job()->handle($preparation);
            $this->fail('The worker should retry an infrastructure failure.');
        } catch (RuntimeException) {
            $this->assertTrue(Cache::has($key));
        }
        Log::shouldReceive('error')->once();
        $this->job()->failed(new RuntimeException('terminal'));
        $this->assertFalse(Cache::has($key));
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_one_service_eligibility_failure_does_not_starve_later_services_or_release_an_existing_gate(): void
    {
        $this->calibrationChecks();
        Bus::fake();
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 1);

        $later = MonitoredService::factory()->create([
            'check_type' => 'api', 'is_active' => true, 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440,
        ]);
        for ($day = 0; $day < 28; $day++) {
            $at = Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day);
            $later->serviceChecks()->make([
                'checked_at' => $at, 'source' => ServiceCheck::SOURCE_AUTOMATIC,
                'check_type' => 'api', 'is_success' => true, 'response_time_ms' => 100,
                'is_during_maintenance' => false,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        $eligibility = $this->partialMock(EligibleSyntheticChecks::class);
        $eligibility->shouldReceive('maintenance')->andReturnUsing(function (MonitoredService $service) {
            if ($service->id === $this->service->id) {
                throw new RuntimeException('One service has an eligibility query failure.');
            }

            return collect();
        });

        $this->artisan('slo:prepare-due')->assertFailed();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 2);
        Bus::assertDispatched(PrepareAutomaticSloProposal::class,
            fn (PrepareAutomaticSloProposal $job): bool => $job->serviceId === $later->id);
        $this->assertTrue(Cache::has(SloAutomaticPreparation::statusKey($this->service->id)));

        $this->artisan('slo:prepare-due')->assertFailed();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 2);
    }

    public function test_terminal_failure_remains_logged_when_status_cache_cleanup_fails(): void
    {
        Cache::shouldReceive('forget')->once()->andThrow(new RuntimeException('cache unavailable'));
        Log::shouldReceive('error')->once()->with('Automatic SLO proposal job failed.', \Mockery::type('array'));
        Log::shouldReceive('warning')->once()->with('Automatic SLO proposal status cleanup failed.', \Mockery::type('array'));

        $this->job()->failed(new RuntimeException('terminal'));
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    private function job(): PrepareAutomaticSloProposal
    {
        return new PrepareAutomaticSloProposal($this->service->id, '2026-11-14T21:00:00+00:00',
            'Asia/Damascus', SloCalibrationAnalysis::POLICY_VERSION, $this->fingerprint());
    }

    private function fingerprint(): string
    {
        return app(SloCalibrationAnalysis::class)->configurationFingerprint($this->service, 'Asia/Damascus');
    }

    private function calibrationChecks(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $at = Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day);
            $this->check($at, ($day + 1) * 100);
        }
    }

    private function check(Carbon $at, ?int $latency, bool $success = true,
        string $source = ServiceCheck::SOURCE_AUTOMATIC, bool $maintenance = false): void
    {
        $this->service->serviceChecks()->make([
            'checked_at' => $at, 'source' => $source,
            'check_type' => 'http', 'is_success' => $success, 'is_slow' => false,
            'response_time_ms' => $latency, 'is_during_maintenance' => $maintenance,
        ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
