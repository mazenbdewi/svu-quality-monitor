<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Models\AuditLog;
use App\Models\ControlChartBaseline;
use App\Models\ControlChartBaselineSample;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\Baselines\BaselineStatistics;
use App\Services\Baselines\PhaseOneBaselineService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseOneCreationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-03 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['name' => 'Research HTTP', 'created_at' => '2026-01-01', 'check_interval_minutes' => 60]);
        foreach ([100, 110, 130, 160] as $i => $value) {
            $at = Carbon::parse('2026-09-01 00:00:00', 'UTC')->addHours($i);
            $this->service->serviceChecks()->make(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => $i === 3, 'response_time_ms' => $value, 'is_during_maintenance' => false])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    private function data(array $metrics = ['latency', 'problematic']): array
    {
        return ['service_id' => $this->service->id, 'baseline_start' => '2026-09-01 03:00:00', 'baseline_end' => '2026-09-01 07:00:00', 'aggregation_interval' => 'hourly', 'metrics' => $metrics];
    }

    private function assertModalContains($component, array $messages): void
    {
        // Filament renders action-modals as a Livewire partial, separate from the page HTML.
        $html = $component->getMountedActionModalHtml();
        foreach ($messages as $message) {
            $this->assertStringContainsString(e($message), $html);
        }
    }

    public static function selections(): array
    {
        return [[['latency'], ['i_chart', 'mr_chart']], [['problematic'], ['p_chart']], [['latency', 'problematic'], ['i_chart', 'mr_chart', 'p_chart']]];
    }

    #[DataProvider('selections')]
    public function test_real_mounted_action_creates_selected_drafts_with_original_statistics(array $metrics, array $types): void
    {
        $this->actingAs($this->admin)->get('/admin/control-chart-baselines')->assertOk();
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data($metrics))
            ->assertFormFieldExists('service_id', fn (Select $field) => $field->getOptions()[$this->service->id] === 'Research HTTP')
            ->callMountedAction()->assertHasNoActionErrors()->assertRedirect('/admin/control-chart-baselines');
        $rows = ControlChartBaseline::orderBy('id')->get();
        $this->assertSame($types, $rows->pluck('chart_type')->all());
        foreach ($rows as $row) {
            $this->assertSame('draft', $row->status);
            $this->assertNull($row->approved_at);
            $this->assertNull($row->active_key);
            $this->assertSame('Asia/Damascus', $row->analysis_timezone);
            $this->assertSame('2026-09-01 00:00:00', $row->baseline_start->toDateTimeString());
            $this->assertSame('2026-09-01 04:00:00', $row->data_cutoff->toDateTimeString());
            $reference = app(PhaseOneBaselineService::class)->create($this->service, $row->chart_type, '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'Asia/Damascus', $this->admin);
            $this->assertSame($reference->parameters, $row->parameters);
            $this->assertSame($reference->membership_digest, $row->membership_digest);
            $this->assertSame($reference->sample_counts, $row->sample_counts);
            $this->assertSame($reference->coverage_context, $row->coverage_context);
        }
    }

    public function test_cutoff_preserves_no_look_ahead_and_utc_conversion(): void
    {
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->mountAction('create_phase_one_baseline')->fillForm($this->data())
            ->fillForm(['data_cutoff' => '2026-09-01 05:00:00', 'metrics' => ['latency', 'problematic']])->callMountedAction()->assertHasNoActionErrors();
        foreach (ControlChartBaseline::all() as $row) {
            $this->assertSame('2026-09-01 02:00:00', $row->data_cutoff->toDateTimeString());
            $this->assertSame(2, $row->sample_counts['included']);
        }
    }

    public function test_duplicate_component_prevents_partial_group_and_preserves_history(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class);
        $component->callAction('create_phase_one_baseline', data: $this->data(['problematic']))->assertHasNoActionErrors();
        $before = ControlChartBaseline::sole()->toArray();
        $component->callAction('create_phase_one_baseline', data: $this->data())->assertHasActionErrors(['metrics'])->assertNotified(__('baseline_creation.failed_title'));
        $this->assertDatabaseCount('control_chart_baselines', 1);
        $this->assertSame($before, ControlChartBaseline::sole()->toArray());
    }

    public function test_failure_in_second_component_rolls_back_baselines_samples_and_audit(): void
    {
        $this->partialMock(BaselineStatistics::class)->shouldReceive('calculate')->with('mr_chart', \Mockery::any(), \Mockery::any())->andThrow(new \RuntimeException('Sensitive internal fixture detail'));
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->callAction('create_phase_one_baseline', data: $this->data())
            ->assertNotified(__('baseline_creation.failed_title'))->assertDontSee('Sensitive internal fixture detail');
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertSame(0, ControlChartBaselineSample::count());
        $this->assertSame(0, AuditLog::where('event', 'baseline.created')->count());
    }

    public static function invalidInputs(): array
    {
        return [
            [['service_id' => null], 'service_id'],
            [['metrics' => []], 'metrics'],
            [['metrics' => ['c_chart']], 'metrics.0'],
            [['baseline_end' => '2026-09-01 02:00:00'], 'baseline_end'],
            [['data_cutoff' => '2026-09-01 08:00:00'], 'data_cutoff'],
            [['baseline_end' => '2099-09-01 07:00:00'], 'baseline_end'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_form_validation_prevents_invalid_creation(array $changes, string $field): void
    {
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->callAction('create_phase_one_baseline', data: array_replace($this->data(), $changes));
        if ($field === 'service_id') {
            $component->assertHasNoActionErrors()->assertNotified(__('baseline_creation.not_ready'));
        } else {
            $component->assertHasActionErrors([$field]);
        }
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_future_period_has_visible_explanation_instead_of_silent_baseline_error(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline')
            ->fillForm(array_replace($this->data(), ['baseline_end' => '2099-09-01 07:00:00']));
        $this->assertModalContains($component, [__('baseline_creation.future_end')]);
        $component->callMountedAction()->assertHasActionErrors(['baseline_end'])->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_service_without_eligible_latency_is_visible_but_latency_disabled_with_reason(): void
    {
        $this->service->serviceChecks()->update(['is_success' => false]);
        // Record edits occur before cutoff in this isolated historical fixture.
        $this->service->serviceChecks()->update(['updated_at' => '2026-09-01 03:30:00']);
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline')->fillForm($this->data(['problematic']))
            ->assertFormFieldExists('metrics', fn (CheckboxList $field) => $field->isOptionDisabled('latency', '') && ! $field->isOptionDisabled('problematic', ''));
        $this->assertModalContains($component, [__('baseline_creation.no_latency')]);
        $component->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('p_chart', ControlChartBaseline::sole()->chart_type);
    }

    public function test_dns_manual_maintenance_and_late_observations_do_not_enable_options(): void
    {
        $checks = $this->service->serviceChecks()->orderBy('id')->get();
        $checks[0]->forceFill(['check_type' => 'dns', 'updated_at' => '2026-09-01 03:30:00'])->save();
        $checks[1]->forceFill(['source' => 'manual', 'updated_at' => '2026-09-01 03:30:00'])->save();
        $checks[2]->forceFill(['is_during_maintenance' => true, 'updated_at' => '2026-09-01 03:30:00'])->save();
        $checks[3]->forceFill(['created_at' => '2026-09-02', 'updated_at' => '2026-09-02'])->save();
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline')->fillForm($this->data())
            ->assertFormFieldExists('metrics', fn (CheckboxList $field) => $field->isOptionDisabled('latency', '') && $field->isOptionDisabled('problematic', ''));
        $this->assertModalContains($component, [__('baseline_creation.no_latency'), __('baseline_creation.no_binary')]);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified(__('baseline_creation.not_ready'));
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public function test_viewer_and_operator_cannot_mount_creation_and_revoked_admin_cannot_submit(): void
    {
        foreach (['viewer', 'operator'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            Livewire::actingAs($user)->test(ListControlChartBaselines::class)->assertActionHidden('create_phase_one_baseline')->call('mountAction', 'create_phase_one_baseline');
        }
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->mountAction('create_phase_one_baseline')->fillForm($this->data());
        $this->admin->syncRoles(['viewer']);
        $component->call('callMountedAction');
        $this->assertDatabaseCount('control_chart_baselines', 0);
    }

    public static function languages(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('languages')]
    public function test_page_and_form_explain_charts_lifecycle_and_eligibility_in_both_languages(string $locale): void
    {
        app()->setLocale($locale);
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->assertSee(__('baseline_creation.phase_help'))->assertSee(__('baseline_creation.baseline_help'))->assertSee(__('baseline_creation.steps'))
            ->assertSee(__('baseline_creation.group_help'))->assertSee(__('baseline_creation.legacy'))
            ->mountAction('create_phase_one_baseline');
        $this->assertModalContains($component, array_map(fn ($key) => __('baseline_creation.'.$key), ['latency', 'problematic', 'latency_help', 'problematic_help']));
    }

    public function test_existing_approved_reference_remains_byte_for_byte_unchanged(): void
    {
        $engine = app(PhaseOneBaselineService::class);
        $old = $engine->create($this->service, 'i_chart', '2026-09-01T00:00:00Z', '2026-09-01T03:00:00Z', 'UTC', $this->admin);
        $engine->review($old, $this->admin, 'Review fixture', true);
        $engine->approve($old, $this->admin, 'Approved fixture');
        $before = $old->fresh()->toArray();
        $samples = $engine->samples($old);
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)->callAction('create_phase_one_baseline', data: $this->data())->assertHasNoActionErrors();
        $this->assertSame($before, $old->fresh()->toArray());
        $this->assertSame($samples, $engine->samples($old));
    }
}
