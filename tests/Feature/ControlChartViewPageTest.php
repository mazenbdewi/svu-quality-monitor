<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlChartViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_chart_view_page_renders_for_an_authenticated_user(): void
    {
        config(['app.env' => 'local']);

        $service = MonitoredService::factory()->create(['name' => 'SVU Portal']);
        $chart = ControlChart::query()->create([
            'monitored_service_id' => $service->id,
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'period_type' => 'daily',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'center_line' => 1000,
            'ucl' => 1500,
            'lcl' => 500,
            'points_count' => 1,
            'out_of_control_count' => 0,
            'calculated_at' => now(),
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');

        $this->actingAs($user)
            ->get(ControlChartResource::getUrl('view', ['record' => $chart]))
            ->assertOk()
            ->assertSee('SVU Portal')
            ->assertSee('Control Chart — SVU Portal')
            ->assertSee('fi-wi-stats-overview')
            ->assertSee('font-size: 18px !important;')
            ->assertSee(now()->startOfDay()->format('d/m/Y'))
            ->assertSee(now()->endOfDay()->format('d/m/Y'))
            ->assertSee(now()->format('H:i'))
            ->assertSee('1,000.00');
    }
}
