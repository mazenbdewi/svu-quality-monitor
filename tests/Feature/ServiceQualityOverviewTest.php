<?php

namespace Tests\Feature;

use App\Filament\Pages\PerformanceAnalysis;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SloProposal;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\ServiceQualityOverview;
use App\Services\SloProposalService;
use App\Services\Spc\PhaseTwoEvaluator;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ServiceQualityOverviewTest extends TestCase
{
    use RefreshDatabase;

    private MonitoredService $service;

    private User $viewer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->assignRole('viewer');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create([
            'name' => 'University Portal', 'check_type' => 'http', 'created_at' => '2026-10-01 00:00:00',
            'check_interval_minutes' => 1440, 'warning_response_ms' => 2500, 'critical_response_ms' => 500,
        ]);
    }

    public function test_bilingual_missing_proposal_empty_denominators_and_responsive_read_only_render(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $writes = [];
            DB::listen(function ($query) use (&$writes): void {
                if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                    $writes[] = $query->sql;
                }
            });
            $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
                ->set('serviceId', $this->service->id);
            $component->assertSee(__('slo_overview.load'))->assertSee(__('slo_overview.lazy_note'));
            $this->assertFalse($component->get('qualityOverviewLoaded'));
            $component->call('loadQualityOverview');
            $component->assertSee(__('slo_overview.title'))
                ->assertSee(__('slo_overview.no_visible_proposal'))
                ->assertSee(__('slo_overview.no_approved_target'))
                ->assertSee(__('slo_overview.synthetic_duration'))
                ->assertSee('sm:grid-cols-2 xl:grid-cols-4', false)
                ->assertSee('lg:grid-cols-2', false);
            $overview = $component->get('qualityOverview');
            $this->assertNull($overview['sli']['availability_percent']);
            $this->assertNull($overview['sli']['conditional_successful_latency_percent']);
            $this->assertNull($overview['sli']['combined_latency_success_percent']);
            $this->assertSame(0, $overview['sli']['executed_checks']);
            $this->assertNull($overview['approved_proposal']);
            $this->assertNotEmpty($overview['calculated_at']);
            $this->assertSame([], $writes);
        }
    }

    public function test_draft_and_approved_are_distinct_with_retrospective_and_prospective_results(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $restricted = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $restricted->assertSee(__('slo_overview.no_visible_proposal'))
            ->assertDontSee(__('slo_overview.status.draft'));
        $this->assertNull($restricted->get('qualityOverview')['latest_proposal']);
        $draft = Livewire::actingAs($this->admin)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $draft->assertSee(__('slo_overview.status.draft'))
            ->assertSee(__('slo_overview.no_approved_target'));
        $this->assertNull($draft->get('qualityOverview')['sli']['combined_latency_success_percent']);
        $this->assertSame('sre-slo-calibration-v1', $draft->get('qualityOverview')['latest_proposal']['policy_version']);
        $this->assertSame(2800, $draft->get('qualityOverview')['latest_proposal']['proposed_latency_ms']);
        $this->assertFalse($draft->get('qualityOverview')['proposal_access_limited']);

        $this->check('2026-11-14 21:00:00', 100); // Retrospective exact-cutoff observation.
        $manager->review($proposal, $this->admin, 'Provisional research limitations reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research-only objective.');
        foreach (['2026-11-15 21:00:00', '2026-11-16 21:00:00', '2026-11-17 21:00:00'] as $at) {
            $this->check($at, 100);
        }
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $approved = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
                ->set('serviceId', $this->service->id)->call('loadQualityOverview');
            $approved->assertSee(__('slo_overview.status.approved'))
                ->assertSee(__('slo_overview.evaluation_mode.post_calibration_retrospective'))
                ->assertSee(__('slo_overview.evaluation_mode.prospective_after_approval'))
                ->assertSee(__('slo_overview.synthetic_duration'));
            $overview = $approved->get('qualityOverview');
            $this->assertSame('prospective_after_approval', $overview['sli_basis']);
            $this->assertSame('met', $overview['prospective']['target_status']);
            $this->assertSame('retrospective_met', $overview['retrospective']['target_status']);
            $this->assertSame(3, $overview['prospective']['sli']['executed_checks']);
            $this->assertSame(100.0, $overview['sli']['combined_latency_success_percent']);
            $this->assertSame(0, $overview['prospective']['coverage']['missing_opportunities']);
            $this->assertSame(2800, $overview['approved_proposal']['proposed_latency_ms']);
        }
        $this->assertSame(2500, $this->service->fresh()->warning_response_ms);
        $this->assertSame(500, $this->service->fresh()->critical_response_ms);
        $this->assertDatabaseCount('slo_proposals', 1);
        $this->assertSame([], $writes);
    }

    public function test_coverage_and_configuration_incompatibility_are_not_reported_as_success(): void
    {
        $this->calibrationChecks();
        $manager = app(SloProposalService::class);
        $proposal = $manager->prepare($this->service, $this->admin);
        $manager->review($proposal, $this->admin, 'Reviewed.', true);
        $manager->approve($proposal->fresh(), $this->admin, 'Research only.');
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'UTC'));
        $empty = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $this->assertSame('insufficient_evidence', $empty->get('qualityOverview')['prospective']['target_status']);
        $this->assertNull($empty->get('qualityOverview')['prospective']['sli']['combined_latency_success_percent']);
        $empty->assertSee(__('slo_overview.target_status.insufficient_evidence'));

        $this->check('2026-11-16 21:00:00', 100);
        $partial = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $this->assertSame(100.0, $partial->get('qualityOverview')['prospective']['sli']['combined_latency_success_percent']);
        $this->assertSame('insufficient_evidence', $partial->get('qualityOverview')['prospective']['target_status']);
        $this->assertNull($partial->get('qualityOverview')['sli']['combined_latency_success_percent']);
        $this->assertSame('descriptive_28_complete_days', $partial->get('qualityOverview')['sli_basis']);

        $this->service->update(['check_interval_minutes' => 30]);
        $changed = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)->call('loadQualityOverview');
        $this->assertFalse($changed->get('qualityOverview')['prospective']['configuration_compatible']);
        $changed->assertSee(__('slo_overview.warnings.monitoring_configuration_incompatible_with_calibration'));
        $this->assertSame('insufficient_evidence', $changed->get('qualityOverview')['prospective']['target_status']);
    }

    public function test_authorization_and_non_http_service_do_not_load_research(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->actingAs($unprivileged)->get(PerformanceAnalysis::getUrl())->assertForbidden();
        $chartsOnly = User::factory()->create(['is_active' => true]);
        $chartsOnly->givePermissionTo('control_charts.view');
        Livewire::actingAs($chartsOnly)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->assertDontSee(__('slo_overview.title'))
            ->call('loadQualityOverview')
            ->assertForbidden();
        $dns = MonitoredService::factory()->create(['check_type' => 'dns']);
        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $dns->id)->call('loadQualityOverview');
        $this->assertFalse($component->get('qualityOverviewLoaded'));
        $this->assertSame([], $component->get('qualityOverview'));
        $this->assertSame(0, SloProposal::count());
    }

    public function test_percentile_query_is_lazy_and_not_repeated_on_unrelated_rerenders(): void
    {
        $this->check('2026-11-01 00:00:00', 100);
        $rankQueries = 0;
        DB::listen(function ($query) use (&$rankQueries): void {
            if (str_contains(strtoupper($query->sql), 'ROW_NUMBER()')) {
                $rankQueries++;
            }
        });
        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id);
        $this->assertSame(0, $rankQueries);
        $component->call('loadQualityOverview');
        $this->assertSame(1, $rankQueries);
        $calculatedAt = $component->get('qualityOverview')['calculated_at'];
        $component->set('window', '7');
        $this->assertSame(1, $rankQueries);
        $this->assertSame($calculatedAt, $component->get('qualityOverview')['calculated_at']);
    }

    public function test_analysis_error_is_explicit_and_clears_previous_results(): void
    {
        $analysis = Mockery::mock(ServiceQualityOverview::class);
        $analysis->shouldReceive('forService')->once()->andThrow(new RuntimeException('Simulated analysis failure.'));
        $this->app->instance(ServiceQualityOverview::class, $analysis);
        Log::shouldReceive('error')->once();

        $component = Livewire::actingAs($this->viewer)->test(PerformanceAnalysis::class)
            ->set('serviceId', $this->service->id)
            ->call('loadQualityOverview');
        $component->assertSee(__('slo_overview.load_error'))
            ->assertDontSee(__('slo_overview.no_approved_target'));
        $this->assertTrue($component->get('qualityOverviewError'));
        $this->assertFalse($component->get('qualityOverviewLoaded'));
        $this->assertSame([], $component->get('qualityOverview'));
    }

    public static function independentSloSpcCases(): array
    {
        return [
            'both normal' => [120, false, 'met', 'normal'],
            'SPC deterioration while SLO met' => [300, false, 'met', 'signal'],
            'SLO shortfall without SPC signal' => [120, true, 'shortfall', 'normal'],
            'both deteriorated' => [500, true, 'shortfall', 'signal'],
        ];
    }

    #[DataProvider('independentSloSpcCases')]
    public function test_frozen_slo_and_phase_two_spc_remain_independent_in_overview(
        int $evaluationLatency, bool $failedCheck, string $expectedSlo, string $expectedSpc,
    ): void {
        // Four low-variance points establish SPC limits; the full 28-day cohort
        // independently gives R Type 7 P99 = 398 ms and a 400-ms SLO objective.
        for ($day = 0; $day < 28; $day++) {
            $this->check(Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day)->toDateTimeString(),
                $day === 27 ? 500 : 100);
        }
        foreach ([110, 130, 160] as $hour => $latency) {
            $this->check('2026-10-18 0'.($hour + 1).':00:00', $latency);
        }
        $baselines = app(PhaseOneBaselineService::class);
        $baseline = $baselines->create($this->service, 'i_chart', '2026-10-18T00:00:00Z',
            '2026-10-18T04:00:00Z', 'UTC', $this->admin);
        $baselines->review($baseline, $this->admin, 'Small deterministic reference fixture.', true);
        $baselines->approve($baseline->fresh(), $this->admin, 'Research fixture only.');
        $frozenLimits = $baseline->fresh()->parameters;

        $proposals = app(SloProposalService::class);
        $proposal = $proposals->prepare($this->service, $this->admin);
        $this->assertEqualsWithDelta(398, $proposal->calibration_payload['measured_p99_ms'], 1e-9);
        $this->assertSame(400, $proposal->proposed_latency_ms);
        $proposals->review($proposal, $this->admin, 'Provisional tail and coverage reviewed.', true);
        $proposals->approve($proposal->fresh(), $this->admin, 'Research objective only.');

        if ($failedCheck) {
            $failed = $this->check('2026-11-16 19:00:00', 120);
            $failed->forceFill(['is_success' => false])->save();
        }
        $this->check('2026-11-16 20:00:00', $evaluationLatency);
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        $this->actingAs($this->viewer);
        $overview = app(ServiceQualityOverview::class)->forService($this->service);

        $this->assertSame($expectedSlo, $overview['prospective']['target_status']);
        $this->assertSame($expectedSpc, $overview['spc']['i_chart']);
        $this->assertSame($failedCheck ? 2 : 1, $overview['prospective']['sli']['executed_checks']);
        $this->assertSame($expectedSlo === 'met' ? 100.0 : ($evaluationLatency <= 400 ? 50.0 : 0.0),
            $overview['prospective']['sli']['combined_latency_success_percent']);
        $this->assertSame('prospective_after_approval', $overview['sli_basis']);
        $this->assertSame($frozenLimits, $baseline->fresh()->parameters);
        $this->assertSame(400, $proposal->fresh()->proposed_latency_ms);
    }

    public function test_missing_or_incompatible_spc_reference_is_never_displayed_as_normal(): void
    {
        $this->actingAs($this->viewer);
        $overview = app(ServiceQualityOverview::class)->forService($this->service);
        $this->assertSame('not_configured', $overview['spc']['i_chart']);

        foreach ([100, 110, 130, 160] as $hour => $latency) {
            $this->check('2026-10-18 0'.$hour.':00:00', $latency);
        }
        $baselines = app(PhaseOneBaselineService::class);
        $baseline = $baselines->create($this->service, 'i_chart', '2026-10-18T00:00:00Z',
            '2026-10-18T04:00:00Z', 'UTC', $this->admin);
        $baselines->review($baseline, $this->admin, 'Small deterministic reference fixture.', true);
        $baselines->approve($baseline->fresh(), $this->admin, 'Research fixture only.');
        $this->service->update(['url' => 'https://changed.example.test']);

        $changed = app(ServiceQualityOverview::class)->forService($this->service->fresh());
        $this->assertSame('incompatible', $changed['spc']['i_chart']);
        $this->assertNull($changed['approved_proposal']);
    }

    private function calibrationChecks(): void
    {
        for ($day = 0; $day < 28; $day++) {
            $this->check(Carbon::parse('2026-10-18 00:00:00', 'UTC')->addDays($day)->toDateTimeString(), ($day + 1) * 100);
        }
    }

    private function check(string $at, ?int $milliseconds): ServiceCheck
    {
        $check = $this->service->serviceChecks()->make([
            'checked_at' => $at, 'source' => ServiceCheck::SOURCE_AUTOMATIC,
            'check_type' => 'http', 'is_success' => true, 'is_slow' => false,
            'response_time_ms' => $milliseconds, 'is_during_maintenance' => false,
        ]);
        $check->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $check;
    }
}
