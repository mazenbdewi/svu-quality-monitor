<?php

namespace Tests\Feature;

use App\Filament\Pages\EarlyDeteriorationDetection;
use App\Filament\Pages\InstitutionSettingsPage;
use App\Filament\Pages\ReportsPage;
use App\Filament\Pages\SpcResearchMonitoring;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\SpcSignal;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Spc\PhaseTwoEvaluator;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EarlyDeteriorationDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-02 00:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['created_at' => '2026-01-01', 'check_interval_minutes' => 60, 'warning_response_ms' => 1500]);
    }

    private function check(string $at, int $latency): void
    {
        $check = $this->service->serviceChecks()->make([
            'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http',
            'is_success' => true, 'is_slow' => false, 'is_during_maintenance' => false,
            'response_time_ms' => $latency, 'performance_status' => 'healthy',
        ]);
        $check->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function approvedReference(): ControlChartBaseline
    {
        foreach ([100, 110, 130, 160] as $hour => $latency) {
            $this->check("2026-09-01 0{$hour}:00:00", $latency);
        }
        $engine = app(PhaseOneBaselineService::class);
        $baseline = $engine->create($this->service, 'i_chart', '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'UTC', $this->admin);
        $engine->review($baseline, $this->admin, 'Reviewed deterministic reference sample', true);
        $engine->approve($baseline, $this->admin, 'Research reference');

        return $baseline->fresh();
    }

    public function test_primary_navigation_has_six_translated_items_without_duplicates(): void
    {
        $this->actingAs($this->admin);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $groups = Filament::getPanel('admin')->getNavigation();
            $labels = collect($groups)->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel())->all();
            $this->assertCount(6, $labels);
            $this->assertCount(6, array_unique($labels));
            $this->assertContains(__('early_deterioration.title'), $labels);
            $this->assertContains(__('performance_analysis.open'), $labels);
            $this->assertSame($locale === 'ar' ? 'إعداد الأداء المرجعي' : 'Reference Performance Setup', __('baselines.title'));
            $this->assertSame($locale === 'ar' ? 'مراقبة التدهور المبكر' : 'Early Deterioration Monitoring', __('spc_phase2.title'));
            $this->assertSame($locale === 'ar' ? 'إشارات التدهور الإحصائية' : 'Statistical Deterioration Signals', __('spc_phase2.signals'));
        }
    }

    public function test_missing_reference_or_evaluation_never_appears_normal_and_page_is_read_only(): void
    {
        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.not_configured'))
            ->assertDontSee(__('early_deterioration.states.normal'));
        $baseline = $this->approvedReference();
        $tables = ['service_checks', 'control_charts', 'control_chart_points', 'control_chart_baselines', 'spc_monitoring_evaluations', 'spc_monitoring_points', 'spc_signals', 'spc_signal_episodes', 'spc_research_runs', 'spc_incident_links', 'service_incidents', 'reliability_metrics', 'sla_metrics', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $baselineTimestamp = $baseline->updated_at;

        $this->get(EarlyDeteriorationDetection::getUrl(['service' => $this->service->id]))
            ->assertOk()->assertSee(__('early_deterioration.states.awaiting_evaluation'))
            ->assertDontSee(__('early_deterioration.states.normal'))
            ->assertSee(SpcResearchMonitoring::getUrl(['baseline' => $baseline->id]));

        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
        $this->assertTrue($baselineTimestamp->equalTo($baseline->fresh()->updated_at));
    }

    public function test_actual_live_signal_below_operational_slow_threshold_is_displayed_with_detection_time(): void
    {
        $baseline = $this->approvedReference();
        $this->check('2026-09-02 00:01:00', 999);
        $this->travelTo(Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        $signal = SpcSignal::where('baseline_id', $baseline->id)->where('mode', 'live')->firstOrFail();
        $this->assertLessThan($this->service->warning_response_ms, $signal->value);

        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.signal'))
            ->assertSee(__('early_deterioration.latest_live_signal'))
            ->assertSee('1,500 ms')->assertSee('999.00')
            ->assertSee($signal->detected_at->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i:s'));
    }

    public function test_retrospective_signal_is_not_presented_as_live_or_normal(): void
    {
        $baseline = $this->approvedReference();
        $this->check('2026-09-02 00:01:00', 999);
        $this->travelTo(Carbon::parse('2026-09-02 01:00:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service, 'retrospective', Carbon::parse('2026-09-02 00:30:00', 'UTC'));
        $this->assertDatabaseHas('spc_signals', ['baseline_id' => $baseline->id, 'mode' => 'retrospective']);

        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.awaiting_evaluation'))
            ->assertSee(__('early_deterioration.retrospective_only'))
            ->assertDontSee(__('early_deterioration.states.normal'));
    }

    public function test_retired_reference_keeps_actual_signal_visible_only_as_history(): void
    {
        $baseline = $this->approvedReference();
        $this->check('2026-09-02 00:01:00', 999);
        $this->travelTo(Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        app(PhaseOneBaselineService::class)->retire($baseline, $this->admin, 'Measurement phase completed');

        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.not_configured'))
            ->assertSee(__('early_deterioration.latest_live_signal'))
            ->assertSee(__('early_deterioration.historical_signal_note'))
            ->assertDontSee(__('early_deterioration.states.normal'));
        Livewire::withQueryParams(['baseline' => $baseline->id])
            ->actingAs($this->admin)->test(SpcResearchMonitoring::class)
            ->assertSet('baselineId', $baseline->id);
    }

    public function test_compatible_live_point_can_show_normal_and_configuration_change_shows_incompatible(): void
    {
        $this->approvedReference();
        $this->check('2026-09-02 00:01:00', 125);
        $this->travelTo(Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.normal'));

        $this->service->update(['warning_response_ms' => 1700]);
        $this->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.incompatible'));
    }

    public function test_expired_live_evaluation_is_not_presented_as_currently_normal(): void
    {
        $this->approvedReference();
        $this->check('2026-09-02 00:01:00', 125);
        $this->travelTo(Carbon::parse('2026-09-02 00:02:00', 'UTC'));
        app(PhaseTwoEvaluator::class)->evaluate($this->service);
        $this->travelTo(Carbon::parse('2026-09-02 02:00:00', 'UTC'));

        $this->actingAs($this->admin)->get(EarlyDeteriorationDetection::getUrl())
            ->assertOk()->assertSee(__('early_deterioration.states.stale'))
            ->assertDontSee(__('early_deterioration.states.normal'));
    }

    public function test_service_switching_and_permission_checks(): void
    {
        $other = MonitoredService::factory()->create(['name' => 'Second service']);
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        Livewire::actingAs($viewer)->test(EarlyDeteriorationDetection::class)
            ->set('serviceId', $other->id)->assertSee('Second service');

        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized)->get(EarlyDeteriorationDetection::getUrl())->assertForbidden();
        $this->actingAs($viewer)->get(SpcResearchMonitoring::getUrl(['baseline' => 999999]))->assertOk();
    }

    public function test_advanced_evidence_link_selects_approved_reference_and_settings_links_remain_available(): void
    {
        $baseline = $this->approvedReference();
        Livewire::withQueryParams(['baseline' => $baseline->id])
            ->actingAs($this->admin)->test(SpcResearchMonitoring::class)
            ->assertSet('baselineId', $baseline->id);

        $this->actingAs($this->admin)->get(InstitutionSettingsPage::getUrl())
            ->assertOk()->assertSee(__('monitoring.navigation_groups.system').' · '.__('early_deterioration.more'));
        $this->get(MonitoredServiceResource::getUrl())
            ->assertOk()->assertSee(__('monitoring.related_pages'));
        $this->get(ReportsPage::getUrl())
            ->assertOk()->assertSee(ReliabilityMetricResource::getNavigationLabel());
    }
}
