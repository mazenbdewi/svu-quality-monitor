<?php

namespace Tests\Feature;

use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SpcSignal;
use App\Models\User;
use App\Services\Baselines\ResearchSnapshotEncoding;
use App\Services\SloEvaluationAnalysis;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SloProposalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create([
            'check_type' => 'http', 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440, 'warning_response_ms' => 2500, 'critical_response_ms' => 500,
        ]);
    }

    public function test_frozen_snapshot_idempotency_permissions_and_lifecycle(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $draft = $manager->prepare($this->service, $this->admin);
        $this->assertSame($draft->id, $manager->prepare($this->service, $this->admin)->id);
        $this->assertDatabaseCount('slo_proposals', 1);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(28, $draft->calibration_payload['latency_observations']);
        $this->assertSame(2800, $draft->proposed_latency_ms);
        $this->assertSame('99.00', $draft->proposed_compliance_percent);
        $this->assertTrue($draft->calibration_end->equalTo($draft->calibration_cutoff));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $draft->payload_digest);
        SloProposalService::assertIntegrity($draft);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('viewer');
        try {
            $manager->review($draft, $viewer, 'Review', true);
            $this->fail('Viewer review should be forbidden.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }
        $reviewed = $manager->review($draft, $this->admin, 'Provisional tail estimate and coverage reviewed.', true);
        $this->assertSame('reviewed', $reviewed->status);
        $this->assertSame($this->admin->id, $reviewed->reviewed_by);
        $approved = $manager->approve($reviewed, $this->admin, 'Research-only objective, not an operational UX standard.');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($draft->payload_digest, $approved->payload_digest);
        $this->assertSame(ResearchSnapshotEncoding::encode($draft->calibration_payload), ResearchSnapshotEncoding::encode($approved->calibration_payload));
        $this->assertSame($approved->id, $manager->prepare($this->service, $this->admin)->id);
        $this->assertSame('approved', $approved->fresh()->status);

        $this->expectException(LogicException::class);
        $approved->update(['proposed_latency_ms' => 9999]);
    }

    public function test_independent_evaluation_cutoff_denominators_coverage_and_repeatability(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $this->check('2026-11-14 21:00:00', 100); // Included only if evaluation starts at the exact cutoff.
        $this->check('2026-11-15 00:00:00', 100);
        $this->check('2026-11-16 00:00:00', null);
        $this->check('2026-11-16 01:00:00', 100, false);
        $this->check('2026-11-16 02:00:00', 100, true, ServiceCheck::SOURCE_MANUAL);
        $this->check('2026-11-16 03:00:00', 100, true, ServiceCheck::SOURCE_AUTOMATIC, ['is_diagnostic' => true]);
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $start = Carbon::parse('2026-11-15 00:00:00', 'UTC');
        $end = Carbon::parse('2026-11-17 00:00:00', 'UTC');
        $evaluation = app(SloEvaluationAnalysis::class);
        try {
            $evaluation->evaluate($proposal, $start, $end);
            $this->fail('Draft evaluation should be forbidden.');
        } catch (InvalidArgumentException) {
            $this->assertSame('draft', $proposal->fresh()->status);
        }
        $manager->review($proposal, $this->admin, 'Limitations acknowledged.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research-only independent evaluation.');
        $proposal->refresh();
        $before = ServiceCheck::query()->orderBy('id')->get()->toArray();
        $first = $evaluation->evaluate($proposal, $start, $end);
        $this->assertSame($first, $evaluation->evaluate($proposal, $start, $end));
        $this->assertSame(3, $first['sli']['executed_checks']);
        $this->assertSame(2, $first['sli']['successful_checks']);
        $this->assertSame(1, $first['sli']['failed_checks']);
        $this->assertSame(1, $first['sli']['successful_without_valid_latency']);
        $this->assertSame(1, $first['sli']['successful_within_objective']);
        $this->assertEqualsWithDelta(200 / 3, $first['sli']['availability_percent'], 1e-9);
        $this->assertSame(100.0, $first['sli']['conditional_successful_latency_percent']);
        $this->assertEqualsWithDelta(100 / 3, $first['sli']['combined_latency_success_percent'], 1e-9);
        $this->assertSame('post_calibration_retrospective', $first['evaluation_mode']);
        $this->assertSame('retrospective_shortfall', $first['target_status']);
        $this->assertContains('retrospective_observations_precede_objective_approval', $first['warnings']);
        $this->assertSame(0, $first['coverage']['missing_opportunities']);
        $this->assertSame($proposal->proposed_latency_ms, $first['frozen_latency_objective_ms']);
        $this->assertSame($before, ServiceCheck::query()->orderBy('id')->get()->toArray());
        $this->assertSame(0, ControlChartBaseline::count() + SpcSignal::count());
        $this->assertDatabaseCount('slo_proposals', 1);

        $atCutoff = $evaluation->evaluate($proposal, $proposal->calibration_cutoff, $end);
        $this->assertSame(4, $atCutoff['sli']['executed_checks']);

        $this->expectException(InvalidArgumentException::class);
        $evaluation->evaluate($proposal, $proposal->calibration_cutoff->copy()->subSecond(), $end);
    }

    public function test_insufficient_coverage_and_configuration_mismatch_are_not_confirmed_failures(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research only.');
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $start = Carbon::parse('2026-11-16 00:00:00', 'UTC');
        $end = Carbon::parse('2026-11-19 00:00:00', 'UTC');
        $result = app(SloEvaluationAnalysis::class)->evaluate($proposal->fresh(), $start, $end);
        $this->assertSame('insufficient_evidence', $result['target_status']);
        $this->assertNull($result['sli']['combined_latency_success_percent']);
        $this->assertGreaterThan(0, $result['coverage']['missing_opportunities']);
        $this->assertContains('insufficient_coverage', $result['warnings']);

        $this->service->update(['check_interval_minutes' => 30]);
        $this->check('2026-11-16 00:00:00', 100);
        $changed = app(SloEvaluationAnalysis::class)->evaluate($proposal->fresh(), $start, $end);
        $this->assertFalse($changed['configuration_compatible']);
        $this->assertSame('insufficient_evidence', $changed['target_status']);
        $this->assertContains('monitoring_configuration_incompatible_with_calibration', $changed['warnings']);
        $this->assertSame(2800, $changed['frozen_latency_objective_ms']);
    }

    public function test_exact_cutoff_retrospective_and_strictly_post_approval_prospective_periods(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $this->check('2026-11-14 20:59:59', 100); // Before the exclusive calibration end.
        $this->check('2026-11-14 21:00:00', 100); // Exact evaluation start is disjoint.
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Provisional research objective.');
        $proposal->refresh();
        $this->check('2026-11-16 00:00:00', 100);
        $this->check('2026-11-17 00:00:00', 100);
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $evaluation = app(SloEvaluationAnalysis::class);

        $retrospective = $evaluation->evaluate($proposal, $proposal->calibration_cutoff,
            Carbon::parse('2026-11-15 01:00:00', 'UTC'));
        $this->assertSame('post_calibration_retrospective', $retrospective['evaluation_mode']);
        $this->assertSame('retrospective_met', $retrospective['target_status']);
        $this->assertSame(1, $retrospective['sli']['executed_checks']);
        $this->assertSame($proposal->approved_at->toIso8601String(), $retrospective['approved_at']);

        $prospective = $evaluation->evaluate($proposal, Carbon::parse('2026-11-16 00:00:00', 'UTC'),
            Carbon::parse('2026-11-18 00:00:00', 'UTC'));
        $this->assertSame('prospective_after_approval', $prospective['evaluation_mode']);
        $this->assertSame('met', $prospective['target_status']);
        $this->assertSame(2, $prospective['sli']['executed_checks']);
        $this->assertNotContains('retrospective_observations_precede_objective_approval', $prospective['warnings']);
        $this->assertSame(2800, $prospective['frozen_latency_objective_ms']);
        $this->assertSame(2500, $this->service->fresh()->warning_response_ms);
        $this->assertSame(500, $this->service->fresh()->critical_response_ms);

        try {
            $evaluation->evaluate($proposal, Carbon::parse('2026-11-15 00:00:00', 'UTC'),
                Carbon::parse('2026-11-16 00:00:00', 'UTC'));
            $this->fail('An interval straddling approval must be split.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('crossing approval', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $evaluation->evaluate($proposal, $proposal->approved_at, Carbon::parse('2026-11-16 00:00:00', 'UTC'));
    }

    public function test_foreign_key_and_retirement_keep_history(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research only.');
        $retired = $manager->retire($proposal->fresh(), $this->admin, 'Superseded after new calibration.');
        $this->assertSame('retired', $retired->status);
        $this->assertDatabaseCount('slo_proposals', 1);
        $this->expectException(QueryException::class);
        DB::table('slo_proposals')->insert([
            'monitored_service_id' => 999999, 'policy_version' => 'test', 'calibration_start' => now(),
            'calibration_end' => now(), 'calibration_cutoff' => now(), 'analysis_timezone' => 'UTC',
            'configuration_fingerprint' => str_repeat('a', 64), 'identity_digest' => str_repeat('b', 64),
            'payload_digest' => str_repeat('c', 64), 'calibration_payload' => '{}',
            'proposed_compliance_percent' => 99, 'status' => 'draft', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_insufficient_calibration_cannot_be_approved_and_identity_is_database_unique(): void
    {
        $manager = app(SloProposalService::class);
        $insufficient = $manager->prepare($this->service, $this->admin);
        $this->assertSame('insufficient_evidence', $insufficient->calibration_payload['status']);
        $manager->review($insufficient, $this->admin, 'No observations; exploratory only.', true);
        try {
            $manager->approve($insufficient->fresh(), $this->admin, 'Cannot validate.');
            $this->fail('Insufficient calibration should not be approvable.');
        } catch (ValidationException) {
            $this->assertSame('reviewed', $insufficient->fresh()->status);
        }

        $duplicate = $insufficient->getAttributes();
        unset($duplicate['id']);
        $this->expectException(QueryException::class);
        DB::table('slo_proposals')->insert($duplicate);
    }

    public function test_configuration_change_blocks_approval_without_changing_frozen_objective(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $this->service->update(['check_interval_minutes' => 30]);
        try {
            $manager->approve($proposal->fresh(), $this->admin, 'Research only.');
            $this->fail('Changed configuration should prevent approval.');
        } catch (ValidationException) {
            $this->assertSame('reviewed', $proposal->fresh()->status);
            $this->assertSame(2800, $proposal->fresh()->proposed_latency_ms);
        }
    }

    public function test_tampered_approved_objective_is_rejected_by_integrity_check(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research only.');
        DB::table('slo_proposals')->where('id', $proposal->id)->update(['proposed_latency_ms' => 9999]);

        $this->expectException(LogicException::class);
        SloProposalService::assertIntegrity($proposal->fresh());
    }

    public function test_migration_rollback_refuses_to_drop_retained_research_records(): void
    {
        app(SloProposalService::class)->prepare($this->service, $this->admin);
        $migration = require database_path('migrations/2026_11_16_000001_create_slo_proposals.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve retained proposals.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('retained SLO research proposals', $exception->getMessage());
            $this->assertDatabaseCount('slo_proposals', 1);
        }
    }

    private function calibrationChecks(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $this->check(Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day)->toDateTimeString(), ($day + 1) * 100);
        }
    }

    private function check(string $at, ?int $milliseconds, bool $success = true, string $source = ServiceCheck::SOURCE_AUTOMATIC, array $metadata = []): ServiceCheck
    {
        $check = $this->service->serviceChecks()->make([
            'checked_at' => $at, 'source' => $source, 'check_type' => 'http', 'is_success' => $success,
            'is_slow' => false, 'response_time_ms' => $milliseconds, 'is_during_maintenance' => false,
            'metadata' => $metadata,
        ]);
        $check->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $check;
    }
}
