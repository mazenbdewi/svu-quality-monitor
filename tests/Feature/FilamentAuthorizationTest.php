<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Pages\SystemOperationsPage;
use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_viewer_direct_urls_are_read_only(): void
    {
        $viewer = $this->user('viewer');
        $this->assertTrue($viewer->canAccessPanel(Filament::getPanel('admin')));
        $service = MonitoredService::factory()->create();
        $metric = ReliabilityMetric::query()->create(['monitored_service_id' => $service->id, 'period_type' => 'daily', 'period_start' => now()->startOfDay(), 'period_end' => now()->endOfDay(), 'total_checks' => 1, 'successful_checks' => 1, 'failed_checks' => 0, 'slow_checks' => 0, 'availability_percent' => 100]);
        $chart = ControlChart::query()->create(['monitored_service_id' => $service->id, 'chart_type' => 'i_chart', 'metric_name' => 'response_time_ms', 'period_type' => 'daily', 'period_start' => now()->startOfDay(), 'period_end' => now()->endOfDay()]);
        ServiceCheck::query()->create(['monitored_service_id' => $service->id, 'checked_at' => now(), 'is_success' => true, 'is_slow' => false]);

        $this->actingAs($viewer)->get(Dashboard::getUrl())->assertOk();
        $this->actingAs($viewer)->get(MonitoredServiceResource::getUrl())->assertOk();
        $this->actingAs($viewer)->get(ReliabilityMetricResource::getUrl('view', ['record' => $metric]))->assertOk();
        $this->actingAs($viewer)->get(ControlChartResource::getUrl('view', ['record' => $chart]))->assertOk();
        $this->actingAs($viewer)->get(ServiceCheckResource::getUrl())->assertOk();
        $this->actingAs($viewer)->get(MonitoredServiceResource::getUrl('create'))->assertForbidden();
        $this->actingAs($viewer)->get(MonitoredServiceResource::getUrl('edit', ['record' => $service]))->assertForbidden();
        $this->actingAs($viewer)->get(MaintenanceWindowResource::getUrl('create'))->assertForbidden();
        $this->actingAs($viewer)->get(UserResource::getUrl())->assertForbidden();
        $this->actingAs($viewer)->get(NotificationSettingsPage::getUrl())->assertForbidden();
        $this->actingAs($viewer)->get(SystemOperationsPage::getUrl())->assertForbidden();
    }

    public function test_viewer_can_access_panel_during_a_real_filament_request(): void
    {
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get(Dashboard::getUrl())->assertOk();
    }

    public function test_operator_and_administrator_direct_urls_follow_matrix(): void
    {
        $operator = $this->user('operator');
        $administrator = $this->user('administrator');

        $this->assertTrue($operator->can('services.view'));

        $this->actingAs($operator)->get(MonitoredServiceResource::getUrl())->assertOk();
        $this->actingAs($operator)->get(MaintenanceWindowResource::getUrl())->assertOk();
        $this->actingAs($operator)->get(SystemOperationsPage::getUrl())->assertOk();
        $this->actingAs($operator)->get(UserResource::getUrl())->assertForbidden();
        $this->actingAs($operator)->get(NotificationSettingsPage::getUrl())->assertForbidden();
        $this->actingAs($administrator)->get(UserResource::getUrl())->assertOk();
        $this->actingAs($administrator)->get(NotificationSettingsPage::getUrl())->assertOk();
        $this->actingAs($administrator)->get(SystemOperationsPage::getUrl())->assertOk();
    }

    public function test_super_admin_gate_bypass_allows_protected_resource_without_direct_permissions(): void
    {
        $user = $this->user('super_admin');
        $user->syncPermissions([]);
        $this->assertTrue($user->can('users.view'));
        $this->assertTrue($user->can('notifications.manage'));
        $this->actingAs($user)->get(UserResource::getUrl())->assertOk();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
