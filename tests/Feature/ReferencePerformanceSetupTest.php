<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use App\Services\Baselines\ReferencePerformancePreview;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReferencePerformanceSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['check_type' => 'http', 'created_at' => '2026-10-01', 'check_interval_minutes' => 60]);
        foreach ([100, 110, 120, 130] as $hour => $value) {
            $at = Carbon::parse('2026-10-09 00:00:00', 'UTC')->addHours($hour);
            $this->service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true,
                'is_slow' => false, 'response_time_ms' => $value, 'is_during_maintenance' => false])
                ->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function data(array $metrics = ['latency', 'problematic']): array
    {
        return ['service_id' => $this->service->id, 'baseline_start' => '2026-10-09 03:00:00', 'baseline_end' => '2026-10-09 07:00:00',
            'metrics' => $metrics, 'aggregation_interval' => 'hourly'];
    }

    public function test_explicit_preview_is_read_only_and_creation_remains_separate(): void
    {
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data());
        $this->assertFalse($page->instance()->canConfirmBaseline());
        $page->call('checkBaselineQuality');
        $preview = $page->instance()->qualityPreviewCurrent();
        $this->assertSame('needs_review', $preview['status']);
        $this->assertSame(4, $preview['charts']['i_chart']['included']);
        $this->assertSame(3, $preview['charts']['mr_chart']['point_count']);
        $this->assertSame(4, $preview['charts']['p_chart']['nonempty_subgroups']);
        $this->assertEquals(100, $preview['charts']['p_chart']['coverage']);
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('spc_monitoring_evaluations', 0);
        $this->assertDatabaseCount('spc_signals', 0);
        $this->assertTrue($page->instance()->canConfirmBaseline(), json_encode($page->instance()->qualityPreviewCurrent()));
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(['i_chart', 'mr_chart', 'p_chart'], ControlChartBaseline::orderBy('id')->pluck('chart_type')->all());
        $this->assertSame(['draft'], ControlChartBaseline::pluck('status')->unique()->values()->all());
    }

    public function test_service_type_is_enforced_beyond_the_select_options(): void
    {
        $this->service->update(['check_type' => 'dns']);
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline');
        $page->assertFormFieldExists('service_id', fn ($field) => ! array_key_exists($this->service->id, $field->getOptions()));
        $this->expectException(ValidationException::class);
        app(PhaseOneBaselineSubmission::class)->create($this->data(), 'Asia/Damascus', $this->admin);
    }

    public function test_preview_distinguishes_complete_preliminary_and_missing_coverage_without_ninety_five_percent_rule(): void
    {
        config()->set('monitoring.spc.exploratory_min_points', 2);
        $previewer = app(ReferencePerformancePreview::class);
        $start = Carbon::parse('2026-10-09 00:00:00', 'UTC');
        $end = Carbon::parse('2026-10-09 04:00:00', 'UTC');
        $complete = $previewer->analyze($this->service, $start, $end, $end, 'Asia/Damascus', ['latency', 'problematic'], 'hourly');
        $this->assertSame('eligible', $complete['status']);
        $this->service->update(['check_interval_minutes' => 30]);
        $partial = $previewer->analyze($this->service, $start, $end, $end, 'Asia/Damascus', ['latency', 'problematic'], 'hourly');
        $this->assertSame('needs_review', $partial['status']);
        $this->assertSame(4, $partial['charts']['p_chart']['missing']);
        $this->assertEquals(50, $partial['charts']['p_chart']['coverage']);
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_preview_reports_empty_and_insufficient_populations_without_changing_approval_rules(): void
    {
        $previewer = app(ReferencePerformancePreview::class);
        $start = Carbon::parse('2026-10-09 00:00:00', 'UTC');
        $end = Carbon::parse('2026-10-09 04:00:00', 'UTC');
        $this->service->serviceChecks()->delete();
        $empty = $previewer->analyze($this->service, $start, $end, $end, 'Asia/Damascus', ['latency'], 'hourly');
        $this->assertSame('ineligible', $empty['status']);
        $this->assertSame('no_data', $empty['charts']['i_chart']['sufficiency']);

        $at = $start->copy();
        $this->service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true,
            'is_slow' => false, 'response_time_ms' => 100, 'is_during_maintenance' => false])
            ->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $sparse = $previewer->analyze($this->service, $start, $end, $end, 'Asia/Damascus', ['latency'], 'hourly');
        $this->assertSame('needs_review', $sparse['status']);
        $this->assertSame('insufficient', $sparse['charts']['i_chart']['sufficiency']);
        $this->assertSame(0, $sparse['charts']['mr_chart']['point_count']);
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_changed_selection_invalidates_preview_without_changing_utc_cutoff_policy(): void
    {
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data())->call('checkBaselineQuality');
        $this->assertTrue($page->instance()->canConfirmBaseline());
        $page->set('mountedActions.0.data.baseline_end', '2026-10-09T07:01:00');
        $this->assertSame([], $page->instance()->qualityPreviewCurrent());
        $this->assertFalse($page->instance()->canConfirmBaseline());
        $this->assertTrue(Carbon::parse($page->get('mountedActions.0.data.baseline_end'), 'UTC')
            ->equalTo(Carbon::parse($page->get('mountedActions.0.data.data_cutoff'), 'UTC')));
        $page->set('mountedActions.0.data.metrics', ['latency', 'problematic']);
        $page->call('checkBaselineQuality');
        $this->assertTrue($page->instance()->canConfirmBaseline(), json_encode($page->instance()->qualityPreviewCurrent()));
    }

    public function test_submission_rechecks_population_after_preview_and_rolls_back_if_observations_disappear(): void
    {
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data())->call('checkBaselineQuality');
        $this->assertTrue($page->instance()->canConfirmBaseline());
        $this->service->serviceChecks()->delete();
        $page->callMountedAction()->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('control_chart_baseline_samples', 0);
        $this->assertDatabaseCount('spc_monitoring_evaluations', 0);
    }

    public function test_p_only_failure_observations_can_be_previewed_and_drafted_without_latency(): void
    {
        $this->service->serviceChecks()->delete();
        foreach (['2026-10-09 00:00:00', '2026-10-09 01:00:00'] as $at) {
            $this->service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => false,
                'is_slow' => false, 'response_time_ms' => null, 'is_during_maintenance' => false])
                ->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data(['problematic']))->call('checkBaselineQuality');
        $this->assertSame(['p_chart'], array_keys($page->instance()->qualityPreviewCurrent()['charts']));
        $this->assertSame(2, $page->instance()->qualityPreviewCurrent()['charts']['p_chart']['included']);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('draft', ControlChartBaseline::sole()->status);
        $this->assertDatabaseCount('spc_monitoring_evaluations', 0);
    }

    public function test_preview_does_not_write_research_operational_or_audit_tables(): void
    {
        $tables = ['control_chart_baselines', 'control_chart_baseline_samples', 'control_charts', 'control_chart_points',
            'spc_monitoring_evaluations', 'spc_monitoring_points', 'spc_signals', 'service_incidents', 'reliability_metrics', 'sla_metrics', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data())->call('checkBaselineQuality');
        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
    }

    public function test_prefilled_utc_period_displays_in_institution_time_and_stores_original_instants(): void
    {
        $page = Livewire::withQueryParams([
            'prefill_service' => $this->service->id,
            'prefill_start' => '2026-10-09 00:00:00',
            'prefill_end' => '2026-10-09 04:00:00',
        ])->actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline');
        $this->assertSame('2026-10-09 03:00:00', $page->get('mountedActions.0.data.baseline_start'));
        $this->assertSame('2026-10-09 07:00:00', $page->get('mountedActions.0.data.baseline_end'));
        $page->call('checkBaselineQuality')->callMountedAction()->assertHasNoActionErrors();
        foreach (ControlChartBaseline::all() as $baseline) {
            $this->assertSame('2026-10-09 00:00:00', $baseline->baseline_start->toDateTimeString());
            $this->assertSame('2026-10-09 04:00:00', $baseline->baseline_end->toDateTimeString());
            $this->assertTrue($baseline->data_cutoff->equalTo($baseline->baseline_end));
        }
    }

    public function test_unpreviewed_submit_and_revoked_permission_cannot_create_drafts(): void
    {
        $page = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data());
        $page->callMountedAction()->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $page->call('checkBaselineQuality');
        $this->admin->syncRoles(['viewer']);
        $page->call('callMountedAction');
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('spc_monitoring_evaluations', 0);
    }

    public function test_viewer_and_operator_cannot_request_quality_preview(): void
    {
        foreach (['viewer', 'operator'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            Livewire::actingAs($user)->test(ListControlChartBaselines::class)->call('checkBaselineQuality')->assertForbidden();
        }
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }
}
