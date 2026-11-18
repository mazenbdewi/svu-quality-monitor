<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Jobs\PrepareAutomaticPhaseOneBaseline;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\Baselines\AutomaticPhaseOneBaselineService;
use App\Services\Baselines\PhaseOneBaselineService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AutomaticPhaseOneBaselineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create([
            'name' => 'Automatic policy service',
            'check_type' => 'http',
            'check_interval_minutes' => 60,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_latest_fourteen_complete_local_days_are_frozen_with_hourly_p_and_no_activation(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 5);

        $result = app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);

        $this->assertCount(3, $result['created']);
        $this->assertSame(['i_chart', 'mr_chart', 'p_chart'], ControlChartBaseline::orderBy('id')->pluck('chart_type')->all());
        foreach (ControlChartBaseline::all() as $baseline) {
            $this->assertSame('draft', $baseline->status);
            $this->assertNull($baseline->approved_at);
            $this->assertNull($baseline->active_key);
            $this->assertSame('phase1-automatic-v1', $baseline->selection_policy_version);
            $this->assertSame('2026-10-31 21:00:00', $baseline->baseline_start->toDateTimeString());
            $this->assertSame('2026-11-14 21:00:00', $baseline->baseline_end->toDateTimeString());
            $this->assertTrue($baseline->data_cutoff->eq($baseline->baseline_end));
            $this->assertSame(14, $baseline->review_context['automatic_policy']['actual_window_days']);
            $this->assertSame('quantity_only_no_stability_search', $baseline->review_context['automatic_policy']['selection_guard']);
        }
        $this->assertSame('raw', ControlChartBaseline::where('chart_type', 'i_chart')->value('aggregation_interval'));
        $this->assertSame('hourly', ControlChartBaseline::where('chart_type', 'p_chart')->value('aggregation_interval'));
    }

    public function test_quantity_shortfall_expands_to_twenty_one_days_but_never_for_signals(): void
    {
        $this->checks(10, Carbon::parse('2026-11-10 00:00:00', 'UTC'));
        $this->checks(11, Carbon::parse('2026-10-28 00:00:00', 'UTC'), response: fn (int $i): int => $i === 10 ? 10000 : 100);

        app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);

        foreach (ControlChartBaseline::all() as $baseline) {
            $trace = $baseline->review_context['automatic_policy'];
            $this->assertSame(21, $trace['actual_window_days']);
            $this->assertSame('expanded_for_sample_adequacy', $trace['window_selection_reason']);
            $this->assertSame([14, 21], array_column($trace['attempted_windows'], 'days'));
        }

        $this->service = MonitoredService::factory()->create([
            'name' => 'Unstable automatic policy service',
            'check_type' => 'http',
            'check_interval_minutes' => 60,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->checks(21, Carbon::parse('2026-11-12 00:00:00', 'UTC'), response: fn (int $i): int => $i === 20 ? 10000 : 100);
        app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);
        $i = ControlChartBaseline::where('monitored_service_id', $this->service->id)->where('chart_type', 'i_chart')->sole();
        $this->assertSame(14, $i->review_context['automatic_policy']['actual_window_days']);
        $this->assertContains('exploratory_out_of_control_points_require_review', $i->review_context['automatic_policy']['warnings']);
    }

    public function test_maximum_window_records_insufficient_candidate_and_independent_quality_dimensions(): void
    {
        $this->checks(3, Carbon::parse('2026-10-20 00:00:00', 'UTC'), problematicEvery: 2);

        app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);
        $p = ControlChartBaseline::where('chart_type', 'p_chart')->sole();
        $trace = $p->review_context['automatic_policy'];

        $this->assertSame(28, $trace['actual_window_days']);
        $this->assertSame('maximum_28_days_still_sample_inadequate', $trace['window_selection_reason']);
        $this->assertSame('insufficient', $trace['assessment']['sample_adequacy']['status']);
        $this->assertArrayHasKey('coverage', $trace['assessment']);
        $this->assertArrayHasKey('missing_opportunities', $trace['assessment']);
        $this->assertArrayHasKey('exclusions', $trace['assessment']);
        $this->assertArrayHasKey('statistical_stability', $trace['assessment']);
    }

    public function test_zero_failure_p_is_degenerate_and_cannot_be_approved(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'));
        app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);
        $p = ControlChartBaseline::where('chart_type', 'p_chart')->sole();
        $assessment = $p->review_context['automatic_policy']['assessment'];

        $this->assertSame('degenerate', $p->review_context['sufficiency']);
        $this->assertSame('not_assessable', $assessment['statistical_stability']['status']);
        $this->assertTrue($assessment['p_degeneracy']['zero_nonconforming']);
        $this->assertContains('p_zero_nonconforming_degenerate_limits', $assessment['warnings']);

        $engine = app(PhaseOneBaselineService::class);
        $engine->review($p, $this->admin, 'Reviewed degenerate P limits and sample quality.', true);
        try {
            $engine->approve($p, $this->admin, 'Must not approve a degenerate reference.');
            $this->fail('Degenerate P baseline must not be approved.');
        } catch (ValidationException) {
            $this->assertSame('reviewed', $p->fresh()->status);
        }

        foreach (ControlChartBaseline::whereIn('chart_type', ['i_chart', 'mr_chart'])->get() as $latency) {
            $engine->review($latency, $this->admin, 'Latency candidate reviewed independently from degenerate P.', true);
            $engine->approve($latency, $this->admin, 'Valid latency reference approved independently.');
            $this->assertSame('approved', $latency->fresh()->status);
        }
    }

    public function test_cutoff_excludes_late_records_and_identical_run_is_deduplicated(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 4);
        $late = $this->check('2026-11-11 12:30:00', response: 999, problematic: true);
        $late->forceFill(['created_at' => '2026-11-15 00:00:00', 'updated_at' => '2026-11-15 00:00:00'])->save();

        $first = app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);
        $second = app(AutomaticPhaseOneBaselineService::class)->prepare($this->admin);

        $this->assertCount(3, $first['created']);
        $this->assertCount(0, $second['created']);
        $this->assertCount(3, $second['existing']);
        $this->assertDatabaseCount('control_chart_baselines', 3);
        foreach (ControlChartBaseline::all() as $baseline) {
            $this->assertSame(21, $baseline->sample_counts['included']);
            $sample = $baseline->samples()->where('service_check_id', $late->id)->sole();
            $this->assertFalse($sample->included);
            $this->assertContains('recorded_after_cutoff_or_unknown', $sample->exclusion_reasons);
        }
    }

    public function test_repeat_execution_does_not_modify_an_existing_approved_candidate(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 4);
        $automatic = app(AutomaticPhaseOneBaselineService::class);
        $automatic->prepare($this->admin);
        $i = ControlChartBaseline::where('chart_type', 'i_chart')->sole();
        $engine = app(PhaseOneBaselineService::class);
        $engine->review($i, $this->admin, 'Reviewed automatic latency candidate.', true);
        $engine->approve($i, $this->admin, 'Approved automatic latency reference.');
        $before = $i->fresh()->toArray();
        $samples = $engine->samples($i->fresh());

        $result = $automatic->prepare($this->admin);

        $this->assertCount(0, $result['created']);
        $this->assertCount(3, $result['existing']);
        $this->assertSame($before, $i->fresh()->toArray());
        $this->assertSame($samples, $engine->samples($i->fresh()));
    }

    public function test_permission_is_required_and_no_manual_or_user_triggered_action_is_registered(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');

        try {
            app(AutomaticPhaseOneBaselineService::class)->prepare($viewer);
            $this->fail('Viewer must not prepare Phase I candidates.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('control_chart_baselines', 0);
        }

        foreach ([$viewer, $this->admin] as $user) {
            Livewire::actingAs($user)->test(ListControlChartBaselines::class)
                ->assertDontSee(__('baseline_creation.prepare_automatically'))
                ->assertDontSee(__('baseline_creation.create'));
        }
    }

    public function test_ui_dispatches_bounded_per_service_jobs_without_creating_in_http_request(): void
    {
        Queue::fake();
        MonitoredService::factory()->create(['check_type' => 'api']);
        MonitoredService::factory()->create(['check_type' => 'dns']);

        $count = app(AutomaticPhaseOneBaselineService::class)->dispatch($this->admin);

        $this->assertSame(2, $count);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, 2);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, fn (PrepareAutomaticPhaseOneBaseline $job): bool => $job->cutoff === '2026-11-14T21:00:00+00:00'
            && $job->timezone === 'Asia/Damascus'
            && $job->policyVersion === 'phase1-automatic-v1');
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_scheduled_dispatch_is_bounded_to_active_incomplete_automatic_sets(): void
    {
        Queue::fake();
        $api = MonitoredService::factory()->create(['check_type' => 'api', 'is_active' => true]);
        MonitoredService::factory()->create(['check_type' => 'dns', 'is_active' => true]);
        MonitoredService::factory()->create(['check_type' => 'http', 'is_active' => false]);

        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(['eligible' => 2, 'dispatched' => 2], $counts);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, 2);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, fn (PrepareAutomaticPhaseOneBaseline $job): bool => $job->serviceId === $api->id
            && $job->actorId === null
            && $job->policyVersion === 'phase1-automatic-v1'
            && $job->queue === 'baseline-preparation'
            && $job->timeout === 55);

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'baselines:prepare-due'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('30 0 * * *', $event->expression);
        $this->assertSame('Asia/Damascus', $event->timezone);
    }

    public function test_scheduled_dispatch_is_capped_and_uses_an_isolated_queue(): void
    {
        Queue::fake();
        MonitoredService::factory()->count(6)->create(['check_type' => 'http', 'is_active' => true]);

        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();

        $this->assertSame(['eligible' => 7, 'dispatched' => 5], $counts);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class, 5);
        Queue::assertPushed(PrepareAutomaticPhaseOneBaseline::class,
            fn (PrepareAutomaticPhaseOneBaseline $job): bool => $job->queue === 'baseline-preparation');
        $compose = file_get_contents(base_path('compose.yaml'));
        $this->assertStringContainsString('baseline_queue:', $compose);
        $this->assertStringContainsString('--queue=baseline-preparation', $compose);
    }

    public function test_scheduled_system_preparation_creates_audited_drafts_and_completed_sets_are_not_redispatched(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 4);
        $result = app(AutomaticPhaseOneBaselineService::class)->prepareOne(
            $this->service->id,
            null,
            '2026-11-14T21:00:00+00:00',
            'Asia/Damascus',
            'phase1-automatic-v1',
        );

        $this->assertCount(3, $result['created']);
        $this->assertSame([null], ControlChartBaseline::pluck('created_by')->unique()->values()->all());
        $this->assertSame(['draft'], ControlChartBaseline::pluck('status')->unique()->values()->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'baseline.created', 'actor_id' => null]);

        Queue::fake();
        $counts = app(AutomaticPhaseOneBaselineService::class)->dispatchDue();
        $this->assertSame(['eligible' => 1, 'dispatched' => 0], $counts);
        Queue::assertNothingPushed();
    }

    public function test_a_partial_automatic_set_recovers_only_the_missing_chart_in_the_original_group(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 4);
        $service = app(AutomaticPhaseOneBaselineService::class);
        $service->prepareOne($this->service->id, null, '2026-11-14T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');
        $p = ControlChartBaseline::where('chart_type', 'p_chart')->sole();
        $setId = $p->draft_set_id;

        // Simulate a legacy/interrupted partial set; current creation is atomic.
        DB::table('control_chart_baseline_samples')->where('control_chart_baseline_id', $p->id)->delete();
        DB::table('control_chart_baselines')->where('id', $p->id)->delete();
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00', 'UTC'));

        $result = $service->prepareOne($this->service->id, null, '2026-11-15T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');

        $this->assertCount(1, $result['created']);
        $this->assertSame('p_chart', $result['created'][0]->chart_type);
        $this->assertSame($setId, $result['created'][0]->draft_set_id);
        $this->assertDatabaseCount('control_chart_baselines', 3);
        $this->assertSame(0, ControlChartBaseline::whereIn('chart_type', ['i_chart', 'mr_chart'])->where('version', '>', 1)->count());
    }

    public function test_daily_retry_of_an_unavailable_family_does_not_accumulate_other_drafts(): void
    {
        foreach (range(0, 9) as $hour) {
            $at = Carbon::parse('2026-11-10 00:00:00', 'UTC')->addHours($hour);
            $this->service->serviceChecks()->make([
                'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => false,
                'is_slow' => false, 'response_time_ms' => null, 'performance_status' => 'critical', 'is_during_maintenance' => false,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
        $service = app(AutomaticPhaseOneBaselineService::class);
        $first = $service->prepareOne($this->service->id, null, '2026-11-14T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');
        $this->assertSame(['p_chart'], collect($first['created'])->pluck('chart_type')->all());

        $this->travelTo(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
        $second = $service->prepareOne($this->service->id, null, '2026-11-15T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');

        $this->assertCount(0, $second['created']);
        $this->assertDatabaseCount('control_chart_baselines', 1);
        $this->assertSame('p_chart', ControlChartBaseline::sole()->chart_type);
    }

    public function test_rejected_and_retired_automatic_members_are_never_reactivated_or_replaced(): void
    {
        $this->checks(21, Carbon::parse('2026-11-10 00:00:00', 'UTC'), problematicEvery: 4);
        $automatic = app(AutomaticPhaseOneBaselineService::class);
        $automatic->prepareOne($this->service->id, null, '2026-11-14T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');
        $engine = app(PhaseOneBaselineService::class);
        $i = ControlChartBaseline::where('chart_type', 'i_chart')->sole();
        $p = ControlChartBaseline::where('chart_type', 'p_chart')->sole();
        $engine->review($i, $this->admin, 'Reviewed automatic reference and limitations.', true);
        $engine->approve($i, $this->admin, 'Approved for lifecycle preservation test.');
        $engine->retire($i, $this->admin, 'Retired explicitly for lifecycle preservation test.');
        $engine->reject($p, $this->admin, 'Rejected explicitly for lifecycle preservation test.');
        $before = ControlChartBaseline::orderBy('id')->get()->map->toArray()->all();

        $this->travelTo(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
        $result = $automatic->prepareOne($this->service->id, null, '2026-11-15T21:00:00+00:00', 'Asia/Damascus', 'phase1-automatic-v1');

        $this->assertCount(0, $result['created']);
        $this->assertSame($before, ControlChartBaseline::orderBy('id')->get()->map->toArray()->all());
        $this->assertSame('retired', $i->fresh()->status);
        $this->assertSame('rejected', $p->fresh()->status);
    }

    private function checks(int $count, Carbon $start, int $problematicEvery = 0, ?callable $response = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->check(
                $start->copy()->addHours($i)->toDateTimeString(),
                $response ? $response($i) : 100 + $i,
                $problematicEvery > 0 && $i % $problematicEvery === 0,
            );
        }
    }

    private function check(string $at, int $response = 100, bool $problematic = false): ServiceCheck
    {
        $check = $this->service->serviceChecks()->make([
            'checked_at' => $at,
            'source' => 'automatic',
            'check_type' => 'http',
            'is_success' => true,
            'is_slow' => $problematic,
            'response_time_ms' => $response,
            'performance_status' => $problematic ? 'critical' : 'healthy',
            'is_during_maintenance' => false,
        ]);
        $check->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $check;
    }
}
