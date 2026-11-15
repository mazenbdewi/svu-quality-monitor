<?php

namespace Tests\Feature;

use App\Filament\Pages\PerformanceAnalysis;
use App\Models\AuditLog;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\SloAutomaticPreparation;
use App\Services\SloProposalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SloProposalUiTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    private User $researcher;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->researcher = User::factory()->create(['is_active' => true]);
        $this->researcher->assignRole('administrator');
        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->assignRole('viewer');
        $this->service = MonitoredService::factory()->create([
            'name' => 'Research web service', 'check_type' => 'http', 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440, 'warning_response_ms' => 2500, 'critical_response_ms' => 500,
        ]);
    }

    public function test_opening_page_never_prepares_proposal_and_displays_waiting_state(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
                ->set('serviceId', $this->service->id)
                ->call('loadQualityOverview')
                ->assertSee(__('slo_overview.preparation_state.waiting'));
            $this->assertDatabaseCount('slo_proposals', 0);
            $this->assertSame(0, AuditLog::where('event', 'like', 'slo.proposal.%')->count());
        }
    }

    public function test_authorized_researcher_sees_preparing_then_ready_without_page_triggering_creation(): void
    {
        Cache::put(SloAutomaticPreparation::statusKey($this->service->id), 'queued', 120);
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview')
            ->assertSee(__('slo_overview.preparation_state.preparing'));
        $this->assertDatabaseCount('slo_proposals', 0);
        $viewer = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $viewer->assertDontSee(__('slo_overview.preparation_state.preparing'));
        $this->assertNull($viewer->get('qualityOverview')['preparation_state']);

        Cache::forget(SloAutomaticPreparation::statusKey($this->service->id));
        $this->calibrationChecks();
        app(SloProposalService::class)->prepare($this->service, $this->researcher);
        $this->actingAs($this->researcher);
        $page->call('loadQualityOverview')->assertSee(__('slo_overview.preparation_state.ready_for_review'));
        $this->assertDatabaseCount('slo_proposals', 1);
    }

    public function test_researcher_can_review_approve_and_retire_without_editing_scientific_values(): void
    {
        $this->calibrationChecks();
        $proposal = app(SloProposalService::class)->prepare($this->service, $this->researcher);
        $originalDigest = $proposal->payload_digest;
        $originalPayload = $proposal->calibration_payload;
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $page->assertSee($this->service->name)
            ->assertSee(__('slo_overview.status.draft'))
            ->assertSee(__('slo_overview.compatibility.compatible'))
            ->assertSee('2,800.00 ms')
            ->assertSee('2.800 s')
            ->assertActionVisible('reviewSloProposal')
            ->assertActionHidden('approveSloProposal')
            ->assertActionHidden('retireSloProposal');

        $page->callAction('reviewSloProposal', data: ['notes' => 'Reviewed P99 tail uncertainty.', 'acknowledge' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('reviewed', $proposal->fresh()->status);
        $page->assertActionHidden('reviewSloProposal')->assertActionVisible('approveSloProposal');

        $page->callAction('approveSloProposal', data: ['rationale' => 'Research-only objective, not operational threshold.'])
            ->assertHasNoActionErrors();
        $this->assertSame('approved', $proposal->fresh()->status);
        $page->assertActionHidden('approveSloProposal')->assertActionVisible('retireSloProposal');

        $page->callAction('retireSloProposal', data: ['reason' => 'Configuration research cycle closed.'])
            ->assertHasNoActionErrors();
        $this->assertSame('retired', $proposal->fresh()->status);
        $page->assertActionHidden('retireSloProposal');
        $this->assertSame($originalDigest, $proposal->fresh()->payload_digest);
        // MySQL JSON may reorder object keys without changing the frozen research values.
        $this->assertEquals($originalPayload, $proposal->fresh()->calibration_payload);
        $this->assertSame(2500, $this->service->fresh()->warning_response_ms);
        $this->assertSame(500, $this->service->fresh()->critical_response_ms);
        $this->assertDatabaseCount('slo_proposals', 1);
        foreach (['reviewed', 'approved', 'retired'] as $status) {
            $this->assertSame(1, AuditLog::where('event', 'slo.proposal.'.$status)->count());
        }
    }

    public function test_unprivileged_viewer_cannot_access_draft_or_lifecycle_actions(): void
    {
        $this->calibrationChecks();
        $proposal = app(SloProposalService::class)->prepare($this->service, $this->researcher);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
                ->set('serviceId', $this->service->id)->call('loadQualityOverview');
            $page->assertSee(__('slo_overview.no_visible_proposal'))
                ->assertDontSee(__('slo_overview.status.draft'))
                ->assertActionHidden('reviewSloProposal')
                ->assertActionHidden('approveSloProposal')
                ->assertActionHidden('retireSloProposal');
            $this->assertNull($page->get('qualityOverview')['latest_proposal']);
        }
        $this->assertSame('draft', $proposal->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', 'slo.proposal.reviewed')->count());
    }

    public function test_review_requires_notes_and_acknowledgement_and_approval_respects_current_configuration(): void
    {
        $this->calibrationChecks();
        $proposal = app(SloProposalService::class)->prepare($this->service, $this->researcher);
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $page->callAction('reviewSloProposal', data: ['notes' => '', 'acknowledge' => false])
            ->assertHasActionErrors(['notes', 'acknowledge']);
        $this->assertSame('draft', $proposal->fresh()->status);
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $page->callAction('reviewSloProposal', data: ['notes' => 'Reviewed limitations.', 'acknowledge' => true])
            ->assertHasNoActionErrors();
        $this->service->update(['check_interval_minutes' => 30]);
        $page->call('loadQualityOverview')
            ->assertSee(__('slo_overview.compatibility.incompatible'))
            ->assertActionHidden('approveSloProposal');
        $this->assertSame('reviewed', $proposal->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', 'slo.proposal.approved')->count());
    }

    public function test_insufficient_evidence_proposal_can_be_reviewed_but_not_approved(): void
    {
        $proposal = app(SloProposalService::class)->prepare($this->service, $this->researcher);
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $page->assertSee(__('slo_overview.evidence.insufficient_evidence'))
            ->assertActionVisible('reviewSloProposal');
        $page->callAction('reviewSloProposal', data: ['notes' => 'Insufficient sample; review only.', 'acknowledge' => true])
            ->assertHasNoActionErrors()
            ->assertActionHidden('approveSloProposal');
        $this->assertSame('reviewed', $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->proposed_latency_ms);
    }

    public function test_revoked_research_permission_cannot_complete_an_already_open_review_modal(): void
    {
        $this->calibrationChecks();
        $proposal = app(SloProposalService::class)->prepare($this->service, $this->researcher);
        $page = Livewire::actingAs($this->researcher)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview')
            ->mountAction('reviewSloProposal')
            ->fillForm(['notes' => 'Prepared before revocation.', 'acknowledge' => true]);
        $this->researcher->syncRoles(['viewer']);
        $page->call('callMountedAction');
        $this->assertSame('draft', $proposal->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', 'slo.proposal.reviewed')->count());
    }

    private function calibrationChecks(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $at = Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day);
            $this->service->serviceChecks()->make([
                'checked_at' => $at, 'source' => ServiceCheck::SOURCE_AUTOMATIC,
                'check_type' => 'http', 'is_success' => true, 'is_slow' => false,
                'response_time_ms' => ($day + 1) * 100, 'is_during_maintenance' => false,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }
}
