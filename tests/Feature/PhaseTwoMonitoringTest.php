<?php

namespace Tests\Feature;

use App\Jobs\EvaluateSpcPhaseTwo;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\SpcMonitoringEvaluation;
use App\Models\SpcMonitoringPoint;
use App\Models\SpcSignal;
use App\Models\SpcSignalEpisode;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Spc\PhaseTwoEvaluator;
use App\Services\Spc\SafePhaseTwoDispatch;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseTwoMonitoringTest extends TestCase
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

    public static function ignoredStates(): array
    {
        return [['none'], ['draft'], ['reviewed']];
    }

    #[DataProvider('ignoredStates')]
    public function test_only_approved_baseline_selected(string $state): void
    {
        if ($state !== 'none') {
            $this->baseline(status: $state);
        }
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:01:00');
        $this->assertDatabaseCount('spc_signals', 0);
        $this->assertSame(3, SpcMonitoringEvaluation::where('status', 'no_approved_baseline')->count());
    }

    public static function observations(): array
    {
        return [[999, 'upper'], [1, 'lower'], [125, null]];
    }

    #[DataProvider('observations')]
    public function test_i_uses_fixed_limits_and_direction(int $value, ?string $direction): void
    {
        $b = $this->baseline();
        $before = $b->toArray();
        $c = $this->check('2026-09-02 00:01:00', ['response_time_ms' => $value]);
        $this->evaluate('2026-09-02 00:02:00');
        $p = SpcMonitoringPoint::firstOrFail();
        $this->assertEqualsWithDelta($b->parameters['ucl'], $p->upper_control_limit, 1e-10);
        $this->assertEquals(125, $p->center_line);
        $this->assertSame($c->id, $p->context['source_check_id']);
        $this->assertSame($before, $b->fresh()->toArray());
        $this->assertDatabaseCount('spc_signals', $direction ? 1 : 0);
        if ($direction) {
            $this->assertSame($direction, SpcSignal::first()->direction);
        }
    }

    public static function mismatches(): array
    {
        return [['url', 'https://changed.test'], ['warning_response_ms', 1700], ['critical_response_ms', 3500], ['check_interval_minutes', 5], ['check_type', 'dns']];
    }

    #[DataProvider('mismatches')]
    public function test_incompatible_configuration_does_not_generate_signal(string $field, mixed $value): void
    {
        $this->baseline();
        $this->service->update([$field => $value]);
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:01:00');
        $this->assertSame('baseline_incompatible', SpcMonitoringEvaluation::where('chart_type', 'i_chart')->first()->status);
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_retirement_and_version_change_preserve_old_signals(): void
    {
        $b = $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:01:00');
        $old = SpcSignal::first()->toArray();
        $this->engine->retire($b, $this->admin, 'Replace reference');
        $this->check('2026-09-02 00:02:00', ['response_time_ms' => 1000]);
        $this->evaluate('2026-09-02 00:02:00');
        $this->assertDatabaseCount('spc_signals', 1);
        $v2 = $this->baseline();
        $this->check('2026-09-02 00:03:00', ['response_time_ms' => 1000]);
        $this->evaluate('2026-09-02 00:03:00');
        $this->assertSame($old, SpcSignal::first()->toArray());
        $this->assertEquals($v2->id, SpcSignal::latest('id')->first()->baseline_id);
        $this->assertEquals(2, $v2->version);
    }

    public function test_mr_starts_at_second_phase_two_observation_with_deterministic_ties(): void
    {
        $b = $this->baseline('mr_chart');
        $a = $this->check('2026-09-02 00:01:00', ['response_time_ms' => 100]);
        $c = $this->check('2026-09-02 00:01:00', ['response_time_ms' => 300]);
        $this->evaluate('2026-09-02 00:02:00');
        $points = SpcMonitoringPoint::orderBy('id')->get();
        $this->assertNull($points[0]->value);
        $this->assertSame('awaiting_previous', $points[0]->status);
        $this->assertEquals(200, $points[1]->value);
        $this->assertSame($a->id, $points[1]->context['previous_check_id']);
        $this->assertSame($c->id, $points[1]->context['source_check_id']);
        $this->assertEquals($b->parameters['ucl'], $points[1]->upper_control_limit);
        $this->assertDatabaseCount('spc_signals', 1);
    }

    public function test_p_waits_for_close_and_uses_frozen_p0_with_own_n(): void
    {
        $this->service->serviceChecks()->where('checked_at', '2026-09-01 00:00:00')->update(['is_slow' => true, 'updated_at' => '2026-09-01 00:00:00']);
        $b = $this->baseline('p_chart');
        $this->assertEquals(.25, $b->parameters['p0']);
        $this->check('2026-09-02 00:05:00', ['is_slow' => true]);
        $this->evaluate('2026-09-02 00:59:00');
        $this->assertDatabaseCount('spc_monitoring_points', 0);
        $this->evaluate('2026-09-02 01:00:00');
        $first = SpcMonitoringPoint::first();
        $this->assertEquals(1, $first->subgroup_size);
        $this->assertEquals(.25, $first->center_line);
        $this->assertEquals(1, $first->upper_control_limit);
        for ($i = 0; $i < 12; $i++) {
            $this->check('2026-09-02 01:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00', ['is_slow' => true]);
        }
        $this->evaluate('2026-09-02 02:00:00');
        $last = SpcMonitoringPoint::latest('id')->first();
        $this->assertEquals(12, $last->subgroup_size);
        $this->assertEqualsWithDelta(.25 + 3 * sqrt(.25 * .75 / 12), $last->upper_control_limit, 1e-10);
        $this->assertTrue(SpcSignal::first()->detected_at->gte(Carbon::parse($last->context['bucket_end'])));
        $this->assertSame('upper', SpcSignal::first()->direction);
    }

    public static function incompleteBuckets(): array
    {
        return [[false, 'missing'], [true, 'insufficient_coverage']];
    }

    #[DataProvider('incompleteBuckets')]
    public function test_p_missing_or_partial_never_generates_final_signal(bool $partial, string $status): void
    {
        $this->service->update(['check_interval_minutes' => 5]);
        $this->baseline('p_chart');
        if ($partial) {
            $this->check('2026-09-02 00:01:00', ['is_slow' => true]);
        }
        $this->evaluate('2026-09-02 01:00:00');
        $this->assertSame($status, SpcMonitoringPoint::first()->status);
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_detection_availability_future_data_and_idempotence(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999], '2026-09-02 00:03:00');
        $this->check('2026-09-02 00:10:00', ['response_time_ms' => 1000]);
        $this->evaluate('2026-09-02 00:02:00');
        $this->assertDatabaseCount('spc_signals', 0);
        $this->evaluate('2026-09-02 00:04:00');
        $signal = SpcSignal::first();
        $this->assertSame('2026-09-02 00:04:00', $signal->detected_at->toDateTimeString());
        $this->assertSame('2026-09-02 00:01:00', $signal->observed_at->toDateTimeString());
        $before = $signal->toArray();
        $this->evaluate('2026-09-02 00:05:00');
        $this->assertDatabaseCount('spc_signals', 1);
        $this->assertSame($before, $signal->fresh()->toArray());
    }

    public function test_episodes_combine_nearby_signals_and_split_after_gap(): void
    {
        $this->baseline();
        foreach (['00:01', '00:05', '00:40'] as $at) {
            $this->check('2026-09-02 '.$at.':00', ['response_time_ms' => 999]);
            $this->evaluate('2026-09-02 '.$at.':00');
        }
        $this->assertDatabaseCount('spc_signals', 3);
        $this->assertDatabaseCount('spc_signal_episodes', 2);
        $this->assertEquals(2, SpcSignalEpisode::first()->signal_count);
        $this->assertNotNull(SpcSignalEpisode::first()->closed_at);
    }

    public function test_chart_episodes_remain_separate(): void
    {
        $this->baseline();
        $this->baseline('mr_chart');
        $this->check('2026-09-02 00:01:00');
        $this->check('2026-09-02 00:02:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:02:00');
        $this->assertSame(['i_chart', 'mr_chart'], SpcSignalEpisode::orderBy('chart_type')->pluck('chart_type')->all());
    }

    public function test_live_and_retrospective_evidence_are_separate(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 02:00:00');
        app(PhaseTwoEvaluator::class)->evaluate($this->service, 'retrospective', Carbon::parse('2026-09-02 00:01:00', 'UTC'));
        $this->assertSame(['live', 'retrospective'], SpcSignal::orderBy('id')->pluck('mode')->all());
        $this->assertSame(['2026-09-02 02:00:00', '2026-09-02 00:01:00'], SpcSignal::orderBy('id')->get()->map(fn ($s) => $s->detected_at->toDateTimeString())->all());
    }

    public function test_live_rejects_historical_cutoff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PhaseTwoEvaluator::class)->evaluate($this->service, 'live', now()->subHour());
    }

    public function test_retrospective_replay_rejects_backward_time(): void
    {
        $this->evaluate('2026-09-02 02:00:00', 'retrospective');
        $this->expectException(\InvalidArgumentException::class);
        app(PhaseTwoEvaluator::class)->evaluate($this->service, 'retrospective', Carbon::parse('2026-09-02 01:00:00', 'UTC'));
    }

    public function test_dispatch_failure_is_isolated(): void
    {
        $this->baseline();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        app(SafePhaseTwoDispatch::class)->dispatch($this->service->id);
        $this->assertDatabaseCount('control_chart_baselines', 1);
    }

    public function test_signal_history_cannot_be_rewritten(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:01:00');
        $this->expectException(\LogicException::class);
        SpcSignal::first()->update(['detected_at' => now()->subDay()]);
    }

    public function test_restoring_configuration_does_not_silently_unblock_a_mismatched_baseline(): void
    {
        $this->baseline();
        $original = $this->service->warning_response_ms;
        $this->service->update(['warning_response_ms' => 1700]);
        $this->evaluate('2026-09-02 00:01:00');
        $this->service->update(['warning_response_ms' => $original]);
        $this->check('2026-09-02 00:02:00', ['response_time_ms' => 999]);
        $this->evaluate('2026-09-02 00:02:00');
        $this->assertSame('baseline_incompatible', SpcMonitoringEvaluation::where('chart_type', 'i_chart')->latest('id')->first()->status);
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_mr_frozen_previous_value_survives_raw_edit_and_late_arrival(): void
    {
        $this->baseline('mr_chart');
        $a = $this->check('2026-09-02 00:01:00', ['response_time_ms' => 100]);
        $this->evaluate('2026-09-02 00:01:00');
        $a->update(['response_time_ms' => 9999]);
        $this->check('2026-09-02 00:02:00', ['response_time_ms' => 120]);
        $this->evaluate('2026-09-02 00:02:00');
        $this->assertEquals(20, SpcMonitoringPoint::latest('id')->first()->value);
        $late = $this->check('2026-09-02 00:00:30', ['response_time_ms' => 5000], '2026-09-02 00:03:00');
        $this->evaluate('2026-09-02 00:03:00');
        $this->assertSame('late_out_of_order', SpcMonitoringPoint::where('source_key', 'check:'.$late->id)->first()->status);
        $this->check('2026-09-02 00:04:00', ['response_time_ms' => 140]);
        $this->evaluate('2026-09-02 00:04:00');
        $this->assertEquals(20, SpcMonitoringPoint::latest('id')->first()->value);
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_p_finalized_partial_bucket_is_not_rewritten_by_late_data(): void
    {
        $this->service->update(['check_interval_minutes' => 30]);
        $this->baseline('p_chart');
        $this->check('2026-09-02 00:01:00', ['is_slow' => true]);
        $this->evaluate('2026-09-02 01:00:00');
        $old = SpcMonitoringPoint::first()->toArray();
        $this->check('2026-09-02 00:31:00', ['is_slow' => true], '2026-09-02 01:01:00');
        $this->evaluate('2026-09-02 01:02:00');
        $this->assertSame($old, SpcMonitoringPoint::first()->toArray());
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_future_approval_cannot_be_used_in_a_retrospective_cutoff(): void
    {
        $this->travelTo(Carbon::parse('2026-09-02 02:00:00', 'UTC'));
        $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        app(PhaseTwoEvaluator::class)->evaluate($this->service, 'retrospective', Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        $this->assertDatabaseCount('spc_signals', 0);
    }

    public function test_live_clock_is_captured_after_loading_and_locking_research_context(): void
    {
        $this->baseline();
        $this->check('2026-09-02 00:01:00', ['response_time_ms' => 999]);
        $this->travelTo(Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        $advanced = false;
        DB::listen(function ($query) use (&$advanced) {
            if (! $advanced && str_contains($query->sql, 'monitored_services') && str_starts_with($query->sql, 'select')) {
                $advanced = true;
                $this->travel(2)->minutes();
            }
        });
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        $this->assertSame('2026-09-02 00:04:00', SpcSignal::first()->detected_at->toDateTimeString());
    }

    public function test_r5_queue_delay_is_recorded_without_a_schema_change(): void
    {
        $b = $this->baseline();
        $this->travelTo(Carbon::parse('2026-09-02 00:01:00', 'UTC'));
        $this->check(now()->toDateTimeString(), ['response_time_ms' => 999]);
        $job = new EvaluateSpcPhaseTwo($this->service->id);
        $this->travel(2)->minutes();
        $job->handle(app(PhaseTwoEvaluator::class));
        $evaluation = SpcMonitoringEvaluation::where('baseline_id', $b->id)->firstOrFail();
        $this->assertEquals(120, $evaluation->context['job_timing']['queue_delay_seconds']);
        $this->assertSame('2026-09-02T00:03:00+00:00', $evaluation->context['job_timing']['job_started_at']);
        $this->assertSame('2026-09-02 00:03:00', SpcSignal::first()->detected_at->toDateTimeString());
    }
}
