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

    public function test_performance_page_links_to_selected_service_and_shows_baseline_action_only_to_managers(): void
    {
        $this->actingAs($this->admin)
            ->get(PerformanceAnalysis::getUrl(['service' => $this->service->id]))
            ->assertOk()
            ->assertSee(__('performance_analysis.create_baseline'))
            ->assertSee('prefill_service='.$this->service->id);

        $this->actingAs($this->viewer)
            ->get(PerformanceAnalysis::getUrl(['service' => $this->service->id]))
            ->assertOk()
            ->assertDontSee(__('performance_analysis.create_baseline'));
    }

    public function test_prefilled_baseline_entry_opens_existing_action_without_writing(): void
    {
        $start = '2026-10-01 00:00:00';
        $end = '2026-10-07 00:00:00';

        $page = Livewire::withQueryParams([
            'prefill_service' => $this->service->id,
            'prefill_start' => $start,
            'prefill_end' => $end,
        ])->actingAs($this->admin)->test(ListControlChartBaselines::class);

        $this->assertSame($this->service->id, $page->instance()->baselinePrefill('service_id'));
        $page->mountAction('create_phase_one_baseline');
        $this->assertSame($this->service->id, $page->get('mountedActions.0.data.service_id'));
        $this->assertSame('2026-10-01 03:00:00', $page->get('mountedActions.0.data.baseline_start'));
        $this->assertSame('2026-10-07 03:00:00', $page->get('mountedActions.0.data.baseline_end'));
        $this->assertSame('2026-10-07 03:00:00', $page->get('mountedActions.0.data.data_cutoff'));
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
}
