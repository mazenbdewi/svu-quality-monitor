<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\Baselines\BaselinePopulation;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseOneReactiveEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-04 10:05:43', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['name' => 'Production-like API', 'check_type' => 'api', 'created_at' => '2026-10-01']);
        // Attempt timestamps differ from persistence timestamps, as with real HTTP jobs.
        foreach (['2026-10-03 20:58:57', '2026-10-03 21:00:03', '2026-10-03 21:01:47'] as $i => $at) {
            $this->observation($this->service, $at, $i !== 2, $i === 1);
        }
    }

    private function observation(MonitoredService $service, string $at, bool $success, bool $slow): void
    {
        $recorded = Carbon::parse($at, 'UTC')->addSeconds(2);
        $service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'api', 'is_success' => $success, 'is_slow' => $slow, 'response_time_ms' => $success ? 1423 : null, 'is_during_maintenance' => false])
            ->forceFill(['created_at' => $recorded, 'updated_at' => $recorded])->save();
    }

    private function page()
    {
        return Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline');
    }

    private function assertAvailability($page, int $latency, int $binary): void
    {
        $availability = $page->instance()->currentBaselineAvailability();
        $this->assertSame($latency, $availability['latency']);
        $this->assertSame($binary, $availability['problematic']);
        $submit = $page->instance()->getMountedActions()[0]->getModalSubmitAction();
        $this->assertSame($latency + $binary === 0, $submit->isDisabled());
    }

    public function test_service_update_suggests_available_period_and_enables_both_options_with_counts(): void
    {
        $page = $this->page();
        $this->assertAvailability($page, 0, 0);
        $page->set('mountedActions.0.data.service_id', $this->service->id);
        $this->assertAvailability($page, 2, 3);
        $html = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('2026-10-03 23:58:57', $html);
        $this->assertStringContainsString('2026-10-04 00:01:47', $html);
        $this->assertStringContainsString(e(__('baseline_creation.latency_count', ['count' => 2])), $html);
        $this->assertStringContainsString(e(__('baseline_creation.problematic_count', ['count' => 3])), $html);
        $page->set('mountedActions.0.data.metrics', ['latency', 'problematic'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(['i_chart', 'mr_chart', 'p_chart'], ControlChartBaseline::orderBy('id')->pluck('chart_type')->all());
        $this->assertSame(['draft'], ControlChartBaseline::pluck('status')->unique()->values()->all());
        $this->assertSame('2026-10-04 10:05:43', ControlChartBaseline::first()->data_cutoff->toDateTimeString());
    }

    public function test_change_period_and_cutoff_recalculates_utc_counts_across_damascus_midnight(): void
    {
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id);
        $page->set('mountedActions.0.data.baseline_start', '2026-10-03T23:58:00')
            ->set('mountedActions.0.data.baseline_end', '2026-10-04T00:01:00');
        $this->assertAvailability($page, 2, 2);
        $page->set('mountedActions.0.data.data_cutoff', '2026-10-04T00:00:04');
        // Second observation was attempted at 00:00:03 but only persisted at 00:00:05.
        $this->assertAvailability($page, 1, 1);
        $page->set('mountedActions.0.data.data_cutoff', '2026-10-04T00:00:05');
        $this->assertAvailability($page, 2, 2);
        $page->set('mountedActions.0.data.metrics', ['latency', 'problematic']);
        $page->set('mountedActions.0.data.baseline_start', '2026-10-04T00:00:06');
        $this->assertSame([], $page->get('mountedActions.0.data.metrics'));
        $this->assertAvailability($page, 0, 0);
        $page->set('mountedActions.0.data.data_cutoff', null)->set('mountedActions.0.data.baseline_end', '2026-10-04T00:02:00');
        $this->assertAvailability($page, 0, 1);
    }

    public function test_switch_to_p_only_service_clears_stale_selections_and_allows_p_draft(): void
    {
        $onlyP = MonitoredService::factory()->create(['created_at' => '2026-10-01']);
        $this->observation($onlyP, '2026-10-03 21:03:11', false, false);
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id)->set('mountedActions.0.data.metrics', ['latency', 'problematic']);
        $page->set('mountedActions.0.data.service_id', $onlyP->id);
        $this->assertSame([], $page->get('mountedActions.0.data.metrics'));
        $this->assertAvailability($page, 0, 1);
        $page->set('mountedActions.0.data.metrics', ['problematic'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('p_chart', ControlChartBaseline::sole()->chart_type);
        $this->assertSame(1, ControlChartBaseline::sole()->sample_counts['included']);
    }

    public function test_service_without_data_disables_submit_and_forged_submit_never_shows_required(): void
    {
        $empty = MonitoredService::factory()->create();
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id)->set('mountedActions.0.data.metrics', ['latency']);
        $page->set('mountedActions.0.data.service_id', $empty->id);
        $this->assertAvailability($page, 0, 0);
        $this->assertNull($page->get('mountedActions.0.data.baseline_start'));
        $this->assertStringContainsString(e(__('baseline_creation.no_available_range')), $page->getMountedActionModalHtml());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_empty_period_disables_create_and_recovery_enables_it_without_remount(): void
    {
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id)
            ->set('mountedActions.0.data.baseline_start', '2026-10-02T00:00:00')->set('mountedActions.0.data.baseline_end', '2026-10-02T01:00:00');
        $this->assertAvailability($page, 0, 0);
        $this->assertStringContainsString(e(__('baseline_creation.no_eligible')), $page->getMountedActionModalHtml());
        $page->callMountedAction()->assertHasNoActionErrors()->assertNotified(__('baseline_creation.not_ready'));
        $page->set('mountedActions.0.data.baseline_start', '2026-10-03T23:00:00')->set('mountedActions.0.data.baseline_end', '2026-10-04T01:00:00');
        $this->assertAvailability($page, 2, 3);
        $page->set('mountedActions.0.data.metrics', ['latency', 'problematic'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('control_chart_baselines', 3);
    }

    public function test_preview_counts_match_r4a_capture_with_real_recording_delay_and_exclusions(): void
    {
        $at = Carbon::parse('2026-10-03 21:00:00', 'UTC');
        foreach ([['source' => 'manual'], ['check_type' => 'dns'], ['is_during_maintenance' => true], ['metadata' => ['is_synthetic' => true]], ['metadata' => ['is_diagnostic' => true]]] as $attrs) {
            $this->service->serviceChecks()->make(array_merge(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'is_during_maintenance' => false, 'response_time_ms' => 99], $attrs))->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
        $start = Carbon::parse('2026-10-03 20:00:00', 'UTC');
        foreach (['2026-10-03 21:00:04', '2026-10-03 21:00:05', '2026-10-04 01:00:00'] as $end) {
            $cutoff = Carbon::parse($end, 'UTC');
            $counts = app(PhaseOneBaselineSubmission::class)->availability($this->service, $start, $cutoff, 'Asia/Damascus');
            foreach (['latency' => 'i_chart', 'problematic' => 'p_chart'] as $metric => $chart) {
                $captured = app(BaselinePopulation::class)->capture($this->service, $chart, $start, $cutoff, 'hourly', 'Asia/Damascus');
                $this->assertSame($captured['counts']['included'], $counts[$metric]);
            }
        }
    }

    public function test_automatic_cutoff_follows_end_and_creates_utc_snapshots_without_editing_cutoff(): void
    {
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id);
        $advanced = collect($page->instance()->getSchema('mountedActionSchema0')->getComponents())->first(fn ($component) => $component instanceof Section);
        $this->assertTrue($advanced->isCollapsed());
        $page->assertFormFieldExists('baseline_end', fn ($field) => $field->getMaxDate() === '2026-10-04T13:05:43');
        $this->assertFalse((bool) $page->get('mountedActions.0.data.manual_cutoff'));
        $this->assertSame($page->get('mountedActions.0.data.baseline_end'), $page->get('mountedActions.0.data.data_cutoff'));
        $page->set('mountedActions.0.data.baseline_end', '2026-10-04T00:01:00');
        $this->assertAvailability($page, 2, 2);
        $page->set('mountedActions.0.data.baseline_end', '2026-10-04T00:02:00');
        $this->assertAvailability($page, 2, 3);
        $this->assertSame('2026-10-04 00:02:00', $page->get('mountedActions.0.data.data_cutoff'));
        $page->set('mountedActions.0.data.metrics', ['latency', 'problematic'])->callMountedAction()->assertHasNoActionErrors();
        foreach (ControlChartBaseline::all() as $baseline) {
            $this->assertSame('2026-10-03 21:02:00', $baseline->data_cutoff->toDateTimeString());
            $this->assertTrue($baseline->baseline_end->equalTo($baseline->data_cutoff));
        }
        $this->assertDatabaseCount('control_chart_baselines', 3);
    }

    public function test_future_end_has_immediate_field_error_and_recovers_without_submission(): void
    {
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id)
            ->set('mountedActions.0.data.baseline_end', '2026-10-05T00:00:00');
        $page->assertHasErrors(['mountedActions.0.data.baseline_end']);
        $this->assertStringContainsString(e(__('baseline_creation.future_end')), $page->getMountedActionModalHtml());
        $this->assertAvailability($page, 0, 0);
        $page->callMountedAction()->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $page->set('mountedActions.0.data.baseline_end', '2026-10-04T00:02:00')->assertHasNoErrors();
        $this->assertAvailability($page, 2, 3);
    }

    public function test_manual_cutoff_is_preserved_validated_and_can_return_to_automatic_mode(): void
    {
        $page = $this->page()->set('mountedActions.0.data.service_id', $this->service->id)
            ->set('mountedActions.0.data.baseline_end', '2026-10-04T00:02:00')
            ->set('mountedActions.0.data.data_cutoff', '2026-10-04T00:00:05');
        $this->assertAvailability($page, 2, 2);
        $page->set('mountedActions.0.data.baseline_end', '2026-10-04T00:03:00');
        $this->assertSame('2026-10-04T00:00:05', $page->get('mountedActions.0.data.data_cutoff'));
        foreach (['2026-10-03T23:57:00', '2026-10-04T00:04:00'] as $invalid) {
            $page->set('mountedActions.0.data.data_cutoff', $invalid)->assertHasErrors(['mountedActions.0.data.data_cutoff']);
            $this->assertAvailability($page, 0, 0);
            $this->assertStringContainsString(e(__('baseline_creation.invalid_cutoff')), $page->getMountedActionModalHtml());
            $page->callMountedAction()->assertNotified(__('baseline_creation.not_ready'));
        }
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $page->set('mountedActions.0.data.data_cutoff', '')->assertHasNoErrors();
        $this->assertFalse((bool) $page->get('mountedActions.0.data.manual_cutoff'));
        $this->assertAvailability($page, 2, 3);
        $page->set('mountedActions.0.data.baseline_end', '2026-10-04T00:02:00');
        $this->assertSame('2026-10-04 00:02:00', $page->get('mountedActions.0.data.data_cutoff'));
    }
}
