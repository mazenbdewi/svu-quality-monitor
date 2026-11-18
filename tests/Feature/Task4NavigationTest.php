<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalysisMethodologyPage;
use App\Filament\Pages\PerformanceAnalysis;
use App\Filament\Pages\SpcResearchMonitoring;
use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Task4NavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('viewer');
        $this->service = MonitoredService::factory()->create(['check_type' => 'http', 'created_at' => '2026-01-01']);
    }

    public function test_advanced_research_pages_share_the_advanced_navigation_group(): void
    {
        $group = __('monitoring.navigation_groups.advanced_research');
        $configuredGroups = array_map(fn ($item) => $item->getLabel(), Filament::getPanel('admin')->getNavigationGroups());

        $this->assertContains($group, $configuredGroups);
        $this->assertSame($group, ControlChartBaselineResource::getNavigationGroup());
        $this->assertSame($group, ControlChartResource::getNavigationGroup());
        $this->assertSame($group, SpcResearchMonitoring::getNavigationGroup());
        $this->assertSame($group, AnalysisMethodologyPage::getNavigationGroup());
    }

    public function test_performance_page_never_exposes_manual_baseline_preparation(): void
    {
        foreach ([$this->admin, $this->viewer] as $user) {
            $this->actingAs($user)
                ->get(PerformanceAnalysis::getUrl(['service' => $this->service->id]))
                ->assertOk()
                ->assertDontSee(__('performance_analysis.create_baseline'))
                ->assertDontSee('prefill_service='.$this->service->id);
        }
    }

    public function test_prefill_query_does_not_expose_a_manual_creation_action_or_write(): void
    {
        $start = '2026-10-01 00:00:00';
        $end = '2026-10-07 00:00:00';

        $page = Livewire::withQueryParams([
            'prefill_service' => $this->service->id,
            'prefill_start' => $start,
            'prefill_end' => $end,
        ])->actingAs($this->admin)->test(ListControlChartBaselines::class);

        $page->assertDontSee(__('baseline_creation.create'))
            ->assertDontSee(__('baseline_creation.prepare_automatically'));
        $this->assertFalse(method_exists($page->instance(), 'baselinePrefill'));
        $this->assertSame(0, ControlChartBaseline::count());
    }

    public function test_viewer_cannot_open_baseline_creation_from_direct_url(): void
    {
        $this->actingAs($this->viewer)
            ->get(ControlChartBaselineResource::getUrl('index', [
                'prefill_service' => $this->service->id,
                'prefill_start' => '2026-10-01 00:00:00',
                'prefill_end' => '2026-10-07 00:00:00',
            ]))
            ->assertOk()
            ->assertDontSee(__('baseline_creation.create'));
    }

    public function test_baseline_status_and_chart_labels_are_localized_without_changing_internal_values(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['draft', 'reviewed', 'approved', 'rejected', 'retired'] as $status) {
                $this->assertNotSame('baselines.statuses.'.$status, __('baselines.statuses.'.$status));
            }
            foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
                $this->assertNotSame('baselines.chart_types.'.$type, __('baselines.chart_types.'.$type));
            }
        }
    }
}
