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
use App\Services\Baselines\ResearchSnapshotEncoding;
use App\Services\EligibleSyntheticChecks;
use App\Services\LatencyPercentileAnalysis;
use App\Services\ServiceQualityOverview;
use App\Services\SloAutomaticPreparation;
use App\Services\SloCalibrationAnalysis;
use App\Services\SloEvaluationAnalysis;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AutomaticSloPreparationTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('monitoring.slo.automatic_preparation_enabled', true);
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
        $this->assertTrue($event->filtersPass(app()));

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
                && $job->queue === PrepareAutomaticSloProposal::QUEUE
                && $job->timeout < 60;
        });
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertDispatchedTimes(PrepareAutomaticSloProposal::class, 1);
        $this->assertTrue(Cache::has(SloAutomaticPreparation::statusKey($this->service->id)));
        $this->assertDatabaseCount('slo_proposals', 0);
    }

    public function test_disabling_auto_preparation_blocks_schedule_command_and_pending_job_without_touching_research(): void
    {
        $this->calibrationChecks();
        $pending = $this->job();
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'slo:prepare-due'));
        $this->assertNotNull($event);

        config()->set('monitoring.slo.automatic_preparation_enabled', false);
        $this->assertFalse($event->filtersPass(app()));
        Bus::fake();
        $this->artisan('slo:prepare-due')->assertSuccessful();
        Bus::assertNotDispatched(PrepareAutomaticSloProposal::class);
        $this->assertNull($pending->handle(app(SloAutomaticPreparation::class)));
        $this->assertNull(app(SloAutomaticPreparation::class)->prepareOne($this->service->id,
            $pending->cutoff, $pending->timezone, $pending->policyVersion, $pending->configurationFingerprint));
        $this->assertNull(app(SloProposalService::class)->prepareAutomatically($this->service));
        $this->assertDatabaseCount('slo_proposals', 0);
        $this->assertSame(28, ServiceCheck::count());
        $this->assertSame(0, AuditLog::where('event', 'slo.proposal.created')->count());
    }

    public function test_disabling_auto_preparation_does_not_block_human_review_approval_or_evaluation(): void
    {
        $this->calibrationChecks();
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole('administrator');
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $actor);
        config()->set('monitoring.slo.automatic_preparation_enabled', false);

        $manager->review($proposal, $actor, 'Scientific limitations acknowledged.', true);
        $manager->approve($proposal->fresh(), $actor, 'Research-only objective.');
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame(1, SloProposal::count());
        $this->assertNotNull(app(SloEvaluationAnalysis::class)->evaluate($proposal->fresh(),
            Carbon::parse('2026-11-14 21:00:00', 'UTC'), Carbon::parse('2026-11-15 09:00:00', 'UTC')));
    }

    public function test_frozen_digest_audit_is_read_only_and_flags_tampering(): void
    {
        $this->calibrationChecks();
        $this->job()->handle(app(SloAutomaticPreparation::class));
        $proposal = SloProposal::firstOrFail();
        $original = $proposal->toArray();

        $this->artisan('slo:audit-frozen')->expectsOutput('Frozen SLO proposals checked: 1; verified: 1; mismatches: 0; unverifiable: 0.')->assertSuccessful();
        $this->assertSame($original, $proposal->fresh()->toArray());

        DB::table('slo_proposals')->where('id', $proposal->id)->update(['payload_digest' => str_repeat('0', 64)]);
        $tampered = $proposal->fresh()->toArray();
        $this->artisan('slo:audit-frozen')->expectsOutput('Frozen SLO proposals checked: 1; verified: 0; mismatches: 1; unverifiable: 0.')->assertFailed();
        $this->assertSame($tampered, $proposal->fresh()->toArray());
    }

    public function test_frozen_digest_audit_distinguishes_unverifiable_payload_without_disclosing_it(): void
    {
        $this->calibrationChecks();
        $this->job()->handle(app(SloAutomaticPreparation::class));
        $proposal = SloProposal::firstOrFail();
        DB::table('slo_proposals')->where('id', $proposal->id)->update([
            'calibration_payload' => '{}',
            'payload_digest' => hash('sha256', ResearchSnapshotEncoding::encode([])),
        ]);
        $before = $proposal->fresh()->toArray();

        $this->artisan('slo:audit-frozen')
            ->expectsOutput('Frozen SLO proposals checked: 1; verified: 0; mismatches: 0; unverifiable: 1.')
            ->assertFailed();
        $this->assertSame($before, $proposal->fresh()->toArray());
    }

    public function test_frozen_digest_audit_handles_an_absent_table_without_writing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('slo_proposals')->andReturn(false);
        $this->artisan('slo:audit-frozen')
            ->expectsOutput('slo_proposals: absent; no proposal digests to audit.')
            ->assertSuccessful();
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
        config()->set('monitoring.slo.automatic_preparation_enabled', false);
        $this->job()->handle($preparation);
        $this->assertFalse(Cache::has($key));
        config()->set('monitoring.slo.automatic_preparation_enabled', true);
        Cache::put($key, 'pending', 120);
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

    public function test_deterministic_synthetic_workflow_keeps_calibration_evaluation_and_coverage_distinct(): void
    {
        $this->calibrationChecks(); // Independent sorted latencies: 100, 200, ..., 2800 ms.
        $this->check(Carbon::parse('2026-11-01 00:10:00', 'UTC'), 100, success: false);
        $this->check(Carbon::parse('2026-11-01 00:20:00', 'UTC'), null);
        $this->check(Carbon::parse('2026-11-01 00:30:00', 'UTC'), 100, source: ServiceCheck::SOURCE_MANUAL);
        $this->check(Carbon::parse('2026-11-01 00:40:00', 'UTC'), 100, maintenance: true);
        $this->check(Carbon::parse('2026-11-14 21:00:00', 'UTC'), 9999); // Exclusive calibration end.

        $latency = app(LatencyPercentileAnalysis::class)->analyze($this->service);
        foreach (['p50_ms' => 1450, 'p90_ms' => 2530, 'p95_ms' => 2665, 'p99_ms' => 2773] as $metric => $expected) {
            $this->assertEqualsWithDelta($expected, $latency[$metric], 1e-9);
        }

        $this->job()->handle(app(SloAutomaticPreparation::class));
        $proposal = SloProposal::firstOrFail();
        $payload = $proposal->calibration_payload;
        $this->assertNull($proposal->created_by);
        $this->assertSame('provisional_research_only', $payload['status']);
        $this->assertSame(30, $payload['executed_checks']); // N
        $this->assertSame(29, $payload['successful_checks']); // S
        $this->assertSame(28, $payload['successful_with_valid_latency']); // V
        $this->assertSame(28, $payload['successful_within_proposed_objective']); // G
        $this->assertEqualsWithDelta(100 * 29 / 30, $payload['availability_sli_percent'], 1e-9);
        $this->assertEqualsWithDelta(100, $payload['calibration_conditional_successful_latency_percent'], 1e-9);
        $this->assertEqualsWithDelta(100 * 28 / 30, $payload['calibration_combined_sli_percent'], 1e-9);
        $this->assertEqualsWithDelta(2530, $payload['measured_p90_ms'], 1e-9);
        $this->assertEqualsWithDelta(2773, $payload['measured_p99_ms'], 1e-9);
        $this->assertSame(2800, $proposal->proposed_latency_ms);
        $this->assertSame(0, $payload['coverage']['missing_opportunities']);
        $this->assertContains('combined_target_unattainable_given_calibration_availability', $payload['warnings']);
        $this->assertFalse($payload['calibration_combined_sli_is_independent_evidence']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('administrator');
        $manager = app(SloProposalService::class);
        $this->assertSame($proposal->id, $manager->prepare($this->service, $admin)->id);
        $this->assertDatabaseCount('slo_proposals', 1);
        $manager->review($proposal, $admin, 'Tail uncertainty and coverage reviewed.', true);
        $manager->approve($proposal->fresh(), $admin, 'Research-only objective.');
        $this->check(Carbon::parse('2026-11-16 00:00:00', 'UTC'), 100);
        $this->check(Carbon::parse('2026-11-17 00:00:00', 'UTC'), 3000, success: false);
        $beforeChecks = ServiceCheck::query()->orderBy('id')->get()->toArray();
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));

        $evaluation = app(SloEvaluationAnalysis::class)->evaluate($proposal->fresh(),
            Carbon::parse('2026-11-15 21:00:00', 'UTC'), Carbon::parse('2026-11-17 21:00:00', 'UTC'));
        $this->assertSame('prospective_after_approval', $evaluation['evaluation_mode']);
        $this->assertSame('shortfall', $evaluation['target_status']);
        $this->assertSame(2, $evaluation['sli']['executed_checks']);
        $this->assertSame(1, $evaluation['sli']['successful_within_objective']);
        $this->assertSame(50.0, $evaluation['sli']['availability_percent']);
        $this->assertSame(100.0, $evaluation['sli']['conditional_successful_latency_percent']);
        $this->assertSame(50.0, $evaluation['sli']['combined_latency_success_percent']);
        $this->assertSame(0, $evaluation['coverage']['missing_opportunities']);

        $this->actingAs($admin);
        $overview = app(ServiceQualityOverview::class)->forService($this->service);
        $this->assertSame('insufficient_evidence', $overview['prospective']['target_status']);
        $this->assertGreaterThan(0, $overview['prospective']['coverage']['missing_opportunities']);
        $this->assertSame('post_calibration_retrospective', $overview['sli_basis']);
        $this->assertSame(0.0, $overview['sli']['combined_latency_success_percent']);
        $this->assertSame('not_configured', $overview['spc']['i_chart']);
        $this->assertSame($beforeChecks, ServiceCheck::query()->orderBy('id')->get()->toArray());
        $this->assertSame(2800, $proposal->fresh()->proposed_latency_ms);
        $this->assertSame(0, ControlChartBaseline::count() + SpcSignal::count());
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
