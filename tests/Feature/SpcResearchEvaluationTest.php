<?php

namespace Tests\Feature;

use App\Filament\Pages\SpcResearchMonitoring;
use App\Models\ControlChartBaseline;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\SpcIncidentLink;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcResearchRun;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Spc\PhaseTwoEvaluator;
use App\Services\Spc\SpcResearchEvaluation;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpcResearchEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected MonitoredService $service;

    protected User $admin;

    protected PhaseOneBaselineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-02 00:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['created_at' => '2026-01-01', 'check_interval_minutes' => 60]);
        $this->engine = app(PhaseOneBaselineService::class);
        foreach ([100, 110, 130, 160] as $i => $v) {
            $this->check("2026-09-01 0$i:00:00", ['response_time_ms' => $v]);
        }
    }

    protected function check(string $at, array $attributes = [], ?string $available = null): ServiceCheck
    {
        $c = $this->service->serviceChecks()->make(array_merge(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'is_during_maintenance' => false, 'response_time_ms' => 100, 'performance_status' => 'healthy'], $attributes));
        $c->forceFill(['created_at' => $available ?? $at, 'updated_at' => $available ?? $at])->save();

        return $c;
    }

    protected function baseline(string $type = 'i_chart', string $status = 'approved'): ControlChartBaseline
    {
        $b = $this->engine->create($this->service, $type, '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'UTC', $this->admin);
        if ($status !== 'draft') {
            $this->engine->review($b, $this->admin, 'Accepted small deterministic fixture', true);
        }
        if ($status === 'approved') {
            $this->engine->approve($b, $this->admin, 'Test protocol reference');
        }

        return $b->fresh();
    }

    protected function evaluate(string $at, string $mode = 'live'): void
    {
        $this->travelTo(Carbon::parse($at, 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service, $mode);
    }

    private function continuous(array $signals = [0], int $until = 180): void
    {
        for ($m = 0; $m <= $until; $m += 10) {
            $at = Carbon::parse('2026-09-02 00:00:00', 'UTC')->addMinutes($m)->toDateTimeString();
            $this->check($at, ['response_time_ms' => in_array($m, $signals, true) ? 999 : 125]);
            $this->evaluate($at);
        }
        $this->travel(1)->minutes();
    }

    private function incident(int $minute, array $attributes = []): ServiceIncident
    {
        $at = Carbon::parse('2026-09-02 00:00:00', 'UTC')->addMinutes($minute);

        return ServiceIncident::create(array_merge(['monitored_service_id' => $this->service->id, 'started_at' => $at, 'confirmed_at' => $at->copy()->addMinutes(2), 'status' => 'open', 'incident_type' => 'availability', 'severity' => 'critical', 'failure_count' => 2], $attributes));
    }

    private function report(string $mode = 'live'): SpcResearchRun
    {
        return app(SpcResearchEvaluation::class)->run(Carbon::parse('2026-09-02 00:00:00', 'UTC'), Carbon::parse('2026-09-02 03:00:00', 'UTC'), $mode);
    }

    public function test_exact_lead_recall_precision_unmatched_and_missed_incidents(): void
    {
        $this->baseline();
        $this->continuous([0, 120]);
        $a = $this->incident(20);
        $b = $this->incident(90);
        $run = $this->report();
        $m = $run->metrics['groups'][0];
        $this->assertEquals(2, $m['total_eligible_incidents']);
        $this->assertEquals(1, $m['incidents_with_prior_episode']);
        $this->assertEquals(1, $m['incidents_without_prior_signal']);
        $this->assertEquals(.5, $m['recall']);
        $this->assertEquals(2, $m['eligible_episodes']);
        $this->assertEquals(1, $m['matched_episodes']);
        $this->assertEquals(1, $m['unmatched_episodes']);
        $this->assertEquals(.5, $m['precision']);
        foreach (['mean', 'median', 'min', 'max'] as $stat) {
            $this->assertEquals(1200, $m[$stat.'_lead_seconds']);
        }
        $this->assertEquals(1200, SpcIncidentLink::first()->lead_seconds);
        $this->assertEquals($a->id, SpcIncidentLink::first()->incident_id);
        $this->assertSame('incident_without_prior_spc_signal', $run->dataset[0]['incidents'][1]['status']);
        $this->assertSame('unmatched_signal', $run->dataset[0]['episodes'][1]['status']);
        $this->assertSame($b->id, $run->dataset[0]['incidents'][1]['incident_id']);
    }

    public static function nonLinks(): array
    {
        return [[0], [-1], [61]];
    }

    #[DataProvider('nonLinks')]
    public function test_same_time_prior_or_outside_horizon_incident_does_not_link(int $minute): void
    {
        $this->baseline();
        $this->continuous();
        $this->incident($minute);
        $run = $this->report();
        $this->assertDatabaseCount('spc_incident_links', 0);
        $this->assertEquals(0, $run->metrics['groups'][0]['incidents_with_prior_episode']);
    }

    public function test_different_service_and_unconfirmed_incidents_do_not_link(): void
    {
        $this->baseline();
        $this->continuous();
        $other = MonitoredService::factory()->create();
        $this->incident(20, ['monitored_service_id' => $other->id]);
        $this->incident(30, ['confirmed_at' => null]);
        $this->incident(40, ['confirmed_at' => now()->addHour()]);
        $run = $this->report();
        $this->assertDatabaseCount('spc_incident_links', 0);
        $this->assertEquals(0, $run->metrics['groups'][0]['total_eligible_incidents']);
        $this->assertNull($run->metrics['groups'][0]['recall']);
    }

    public function test_maintenance_excluded_and_followup_censored(): void
    {
        $this->baseline();
        $this->continuous();
        $this->incident(20);
        MaintenanceWindow::create(['name' => 'Planned', 'starts_at' => '2026-09-02 00:15:00', 'ends_at' => '2026-09-02 00:25:00', 'applies_to_all_services' => true]);
        $run = $this->report();
        $this->assertDatabaseCount('spc_incident_links', 0);
        $this->assertSame('planned_maintenance', $run->dataset[0]['excluded_incidents'][0]['reason']);
        $this->assertEquals(1, $run->metrics['groups'][0]['censored_episodes']);
        $this->assertNull($run->metrics['groups'][0]['precision']);
    }

    public function test_missing_evaluation_is_not_assumed_healthy_exposure(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:00:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:00:00');
        $this->evaluate('2026-09-02 00:10:00'); // available recent evidence extends only one scheduled interval
        $this->evaluate('2026-09-02 02:00:00');
        $this->incident(90);
        $run = $this->report();
        $this->assertEquals(0, $run->metrics['groups'][0]['total_eligible_incidents']);
        $this->assertSame('outside_observed_phase_two', $run->dataset[0]['excluded_incidents'][0]['reason']);
        $this->assertSame('awaiting_data', SpcMonitoringEvaluation::where('chart_type', 'i_chart')->latest('id')->first()->status);
    }

    public function test_incompatibility_excludes_incident_and_censors_episode(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:00:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:00:00');
        $this->service->update(['warning_response_ms' => 1700]);
        $this->evaluate('2026-09-02 00:10:00');
        $this->travelTo(Carbon::parse('2026-09-02 03:00:00', 'UTC'));
        $this->incident(20);
        $run = $this->report();
        $this->assertEquals(0, $run->metrics['groups'][0]['total_eligible_incidents']);
        $this->assertEquals(1, $run->metrics['groups'][0]['censored_episodes']);
    }

    public function test_earliest_primary_nearest_secondary_and_no_duplicate_recall(): void
    {
        config(['monitoring.spc.episode_gap_minutes' => 10]);
        $this->baseline();
        $this->continuous([0, 30]);
        $this->incident(40);
        $run = $this->report();
        $links = SpcIncidentLink::orderBy('id')->get();
        $this->assertCount(2, $links);
        $this->assertTrue($links[0]->is_primary);
        $this->assertFalse($links[0]->is_nearest);
        $this->assertTrue($links[1]->is_nearest);
        $this->assertFalse($links[1]->is_primary);
        $this->assertEquals(2400, $links[0]->lead_seconds);
        $this->assertEquals(600, $links[1]->lead_seconds);
        $this->assertEquals(1, $run->metrics['groups'][0]['incidents_with_prior_episode']);
        $this->assertEquals(2, $run->metrics['groups'][0]['matched_episodes']);
    }

    public function test_chart_breakdown_and_any_spc_deduplicate_incident(): void
    {
        $this->baseline();
        $this->baseline('mr_chart');
        $this->continuous([10]);
        $this->incident(30);
        $run = $this->report();
        $this->assertCount(2, $run->metrics['groups']);
        foreach ($run->metrics['groups'] as $g) {
            $this->assertEquals(1, $g['incidents_with_prior_episode']);
        }
        $this->assertEquals(['eligible_incidents' => 1, 'incidents_with_prior_episode' => 1, 'recall' => 1], $run->metrics['any_spc']);
    }

    public function test_immature_episode_is_not_unmatched_and_report_modes_do_not_mix(): void
    {
        $this->baseline();
        $this->continuous([170]);
        $this->incident(175);
        $live = $this->report();
        $retro = $this->report('retrospective');
        $this->assertEquals(1, $live->metrics['groups'][0]['censored_episodes']);
        $this->assertEquals(0, $live->metrics['groups'][0]['unmatched_episodes']);
        $this->assertNull($live->metrics['groups'][0]['precision']);
        $this->assertEquals(0, $retro->metrics['groups'][0]['total_episodes']);
        $this->assertEquals(0, $retro->metrics['groups'][0]['total_eligible_incidents']);
        $this->assertSame('retrospective', $retro->mode);
    }

    public function test_run_snapshot_and_detection_times_survive_later_analysis(): void
    {
        $this->baseline();
        $this->continuous();
        $run = $this->report();
        $old = $run->fresh()->toArray();
        $signal = SpcSignal::first()->toArray();
        $this->incident(20);
        config(['monitoring.spc.association_horizon_minutes' => 30]);
        $next = $this->report();
        $this->assertSame($old, $run->fresh()->toArray());
        $this->assertSame($signal, SpcSignal::first()->toArray());
        $this->assertEquals(60, $run->horizon_minutes);
        $this->assertEquals(30, $next->horizon_minutes);
        $this->assertNotEquals($run->metrics, $next->metrics);
    }

    public function test_detector_does_not_query_incidents_and_keeps_threshold_time(): void
    {
        $this->baseline();
        $this->incident(20);
        $this->check('2026-09-02 00:00:00', ['response_time_ms' => 999, 'performance_status' => 'warning', 'is_slow' => true]);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->evaluate('2026-09-02 00:00:00');
        $this->assertStringNotContainsString('service_incidents', implode(' ', $queries));
        $this->continuous([]);
        $run = $this->report();
        $timeline = $run->dataset[0]['incidents'][0]['timeline'];
        $this->assertSame('2026-09-02T00:00:00+00:00', $timeline['fixed_threshold_available']);
        $this->assertSame('2026-09-02T00:20:00+00:00', $timeline['incident_started']);
        $this->assertSame('2026-09-02T00:22:00+00:00', $timeline['incident_confirmed']);
    }

    public function test_ui_renders_chart_modes_report_timeline_and_export(): void
    {
        $b = $this->baseline();
        $this->continuous();
        $this->incident(20);
        Livewire::actingAs($this->admin)->test(SpcResearchMonitoring::class)->set('baselineId', $b->id)->set('periodStart', '2026-09-02T00:00')->set('periodEnd', '2026-09-02T03:00')->call('generateReport')->assertHasNoErrors()->assertSee('primary / earliest')->assertSee('1200')->call('exportReport')->assertFileDownloaded('spc-research-run-1.json');
    }

    public function test_viewer_can_view_but_cannot_generate_research_run(): void
    {
        $this->baseline();
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        Livewire::actingAs($viewer)->test(SpcResearchMonitoring::class)->assertOk()->call('generateReport')->assertForbidden();
        $this->assertDatabaseCount('spc_research_runs', 0);
    }

    public function test_past_report_cutoff_excludes_incident_recorded_later(): void
    {
        $this->baseline();
        $this->continuous();
        $this->incident(20);
        $run = app(SpcResearchEvaluation::class)->run(Carbon::parse('2026-09-02 00:00:00', 'UTC'), Carbon::parse('2026-09-02 02:00:00', 'UTC'), cutoff: Carbon::parse('2026-09-02 02:00:00', 'UTC'));
        $this->assertEquals(0, $run->metrics['groups'][0]['total_eligible_incidents']);
        $this->assertDatabaseCount('spc_incident_links', 0);
    }

    public function test_report_normalizes_non_utc_period_without_shifting_the_cohort(): void
    {
        $this->baseline();
        $this->continuous();
        $this->incident(20);
        $run = app(SpcResearchEvaluation::class)->run(Carbon::parse('2026-09-02 03:00:00', 'Asia/Damascus'), Carbon::parse('2026-09-02 06:00:00', 'Asia/Damascus'));
        $this->assertSame('2026-09-02 00:00:00', $run->period_start->toDateTimeString());
        $this->assertEquals(1, $run->metrics['groups'][0]['total_eligible_incidents']);
    }

    public function test_episode_origin_cannot_be_changed_by_bulk_update(): void
    {
        $this->baseline();
        $this->continuous();
        $this->expectException(\LogicException::class);
        SpcSignalEpisode::query()->update(['first_detected_at' => now()->subDay()]);
    }
}
