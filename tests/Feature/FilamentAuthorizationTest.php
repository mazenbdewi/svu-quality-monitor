<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\InstitutionSettingsPage;
use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Pages\ReportsPage;
use App\Filament\Pages\SystemOperationsPage;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\ControlCharts\ControlChartResource;
use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;
use App\Filament\Resources\ServiceChecks\ServiceCheckResource;
use App\Filament\Resources\ServiceIncidents\ServiceIncidentResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ControlChart;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function remainingRoles(): array
    {
        return [['viewer'], ['operator'], ['administrator'], ['super_admin']];
    }

    #[DataProvider('remainingRoles')]
    public function test_remaining_direct_urls_follow_matrix(string $role): void
    {
        $this->actingAs($this->user($role));
        $admin = in_array($role, ['administrator', 'super_admin'], true);
        $service = MonitoredService::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);
        $window = MaintenanceWindow::query()->create(['name' => 'Planned', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'applies_to_all_services' => true]);
        $urls = [
            [ReportsPage::getUrl(), true],
            [ServiceIncidentResource::getUrl(), true],
            [ReliabilityMetricResource::getUrl(), true],
            [ControlChartResource::getUrl(), true],
            [MaintenanceWindowResource::getUrl(), true],
            [NotificationDeliveryResource::getUrl(), $role !== 'viewer'],
            [InstitutionSettingsPage::getUrl(), $admin],
            [AuditLogResource::getUrl(), $role !== 'operator'],
            [MonitoredServiceResource::getUrl('create'), $admin],
            [MonitoredServiceResource::getUrl('edit', ['record' => $service]), $admin],
            [UserResource::getUrl('create'), $admin],
            [UserResource::getUrl('edit', ['record' => $target]), $admin],
            [MaintenanceWindowResource::getUrl('create'), $role !== 'viewer'],
            [MaintenanceWindowResource::getUrl('edit', ['record' => $window]), $role !== 'viewer'],
        ];
        foreach ($urls as [$url, $allowed]) {
            $this->get($url)->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_inactive_and_roleless_users_cannot_access_panel(): void
    {
        $inactive = $this->user('super_admin');
        $inactive->update(['is_active' => false]);
        $roleless = User::factory()->create(['is_active' => true]);
        foreach ([$inactive, $roleless] as $user) {
            $this->actingAs($user)->get('/admin')->assertForbidden();
            $this->get(MonitoredServiceResource::getUrl())->assertForbidden();
            $this->get(UserResource::getUrl())->assertForbidden();
        }
    }
}
