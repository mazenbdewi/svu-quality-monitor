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
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_permission_is_required_and_page_registers_the_single_standard_action(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');

        try {
            app(AutomaticPhaseOneBaselineService::class)->prepare($viewer);
            $this->fail('Viewer must not prepare Phase I candidates.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('control_chart_baselines', 0);
        }

        Livewire::actingAs($viewer)->test(ListControlChartBaselines::class)
            ->assertActionHidden('prepare_reference_performance_automatically');
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->assertActionVisible('prepare_reference_performance_automatically');
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
