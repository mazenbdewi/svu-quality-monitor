<?php

namespace Tests\Feature;

use App\Filament\Pages\InstitutionSettingsPage;
use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\MaintenanceWindows\Pages\CreateMaintenanceWindow;
use App\Filament\Resources\MaintenanceWindows\Pages\EditMaintenanceWindow;
use App\Filament\Resources\MonitoredServices\Pages\CreateMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\EditMonitoredService;
use App\Filament\Resources\MonitoredServices\Pages\ListMonitoredServices;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\ServiceIncidents\Pages\ListServiceIncidents;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\InstitutionSetting;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\ServiceCheckRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function login(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_viewer_cannot_execute_check_now(): void
    {
        $this->login('viewer');
        $service = MonitoredService::factory()->create(['is_active' => true]);
        $this->mock(ServiceCheckRunner::class)->shouldNotReceive('run');
        Livewire::test(ListMonitoredServices::class)
            ->mountAction(TestAction::make('check_now')->table($service))
            ->assertForbidden();
        $this->assertDatabaseCount('service_checks', 0);
    }

    public function test_operator_can_execute_check_now(): void
    {
        $this->login('operator');
        Http::fake(['*' => Http::response('OK', 200)]);
        $service = MonitoredService::factory()->create(['is_active' => true, 'check_type' => 'http', 'expected_keyword' => null, 'notifications_enabled' => false]);
        Livewire::test(ListMonitoredServices::class)
            ->callAction(TestAction::make('check_now')->table($service))
            ->assertSuccessful();
        $this->assertDatabaseHas('service_checks', ['monitored_service_id' => $service->id, 'is_success' => true]);
    }

    public function test_viewer_cannot_export_reports(): void
    {
        $this->login('viewer');
        Livewire::test(ReportsPage::class)->call('export')->assertForbidden();
    }

    public function test_operator_can_export_reports(): void
    {
        $this->login('operator');
        $service = MonitoredService::factory()->create(['is_active' => true]);
        ServiceCheck::query()->create(['monitored_service_id' => $service->id, 'checked_at' => now(), 'is_success' => true, 'is_slow' => false]);
        Livewire::test(ReportsPage::class)->call('export')->assertSuccessful()->assertFileDownloaded();
    }

    public static function createPages(): array
    {
        return [
            [CreateMonitoredService::class, MonitoredService::class],
            [CreateUser::class, User::class],
            [CreateMaintenanceWindow::class, MaintenanceWindow::class],
        ];
    }

    #[DataProvider('createPages')]
    public function test_create_rechecks_permission_after_role_revocation(string $page, string $model): void
    {
        $user = $this->login('administrator');
        $component = Livewire::test($page);
        $before = $model::query()->count();
        $user->syncRoles(['viewer']);
        $component->call('create')->assertForbidden();
        $this->assertSame($before, $model::query()->count());
    }

    public static function readOnlyServiceRoles(): array
    {
        return [['viewer'], ['operator']];
    }

    #[DataProvider('readOnlyServiceRoles')]
    public function test_unauthorized_user_cannot_force_bulk_service_deletion(string $role): void
    {
        $this->login($role);
        $service = MonitoredService::factory()->create(['is_active' => true]);
        Livewire::test(ListMonitoredServices::class)
            ->assertActionHidden(TestAction::make('delete')->table()->bulk())
            ->selectTableRecords([$service->id])
            ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
            ->call('callMountedAction');
        $this->assertModelExists($service);
    }

    public function test_administrator_can_bulk_delete_services(): void
    {
        $this->login('administrator');
        $service = MonitoredService::factory()->create(['is_active' => true]);
        Livewire::test(ListMonitoredServices::class)
            ->selectTableRecords([$service->id])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertSuccessful();
        $this->assertModelMissing($service);
    }

    public static function editPages(): array
    {
        return [
            [EditMonitoredService::class, MonitoredService::class],
            [EditUser::class, User::class],
        ];
    }

    public function test_maintenance_delete_rechecks_permission_after_role_revocation(): void
    {
        $user = $this->login('operator');
        $window = MaintenanceWindow::query()->create(['name' => 'Planned', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'applies_to_all_services' => true]);
        $component = Livewire::test(EditMaintenanceWindow::class, ['record' => $window->getRouteKey()]);
        $component->mountAction('delete');
        $user->syncRoles(['viewer']);
        $component->call('callMountedAction')->assertForbidden();
        $this->assertModelExists($window);
    }

    #[DataProvider('editPages')]
    public function test_edit_save_rechecks_permission_after_role_revocation(string $page, string $model): void
    {
        $user = $this->login('administrator');
        $record = $model::factory()->create(['is_active' => true]);
        $component = Livewire::test($page, ['record' => $record->getRouteKey()]);
        $before = $record->fresh()->getAttributes();
        $component->set('data.name', 'Unauthorized change');
        $user->syncRoles(['viewer']);
        $component->call('save')->assertForbidden();
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public function test_viewer_cannot_force_hidden_acknowledgement(): void
    {
        $this->login('viewer');
        $incident = $this->incident();
        Livewire::test(ListServiceIncidents::class)
            ->assertActionHidden(TestAction::make('acknowledge')->table($incident))
            ->call('mountAction', 'acknowledge', [], ['table' => true, 'recordKey' => (string) $incident->id])
            ->call('callMountedAction');
        $this->assertNull($incident->fresh()->acknowledged_at);
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_operator_can_acknowledge_without_resolving(): void
    {
        $operator = $this->login('operator');
        $incident = $this->incident();
        Livewire::test(ListServiceIncidents::class)
            ->callAction(TestAction::make('acknowledge')->table($incident))
            ->assertSuccessful();
        $this->assertSame($operator->id, $incident->fresh()->acknowledged_by);
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_operator_cannot_force_hidden_notification_retry(): void
    {
        Queue::fake();
        $this->login('operator');
        $delivery = NotificationDelivery::query()->create(['event_type' => 'manual_test', 'channel' => 'email', 'status' => 'failed', 'deduplication_key' => 'authorization-retry']);
        Livewire::test(ListNotificationDeliveries::class)
            ->assertActionHidden(TestAction::make('retry')->table($delivery))
            ->call('mountAction', 'retry', [], ['table' => true, 'recordKey' => (string) $delivery->id])
            ->call('callMountedAction');
        $this->assertSame('failed', $delivery->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_administrator_can_retry_failed_delivery(): void
    {
        Queue::fake();
        $this->login('administrator');
        $delivery = NotificationDelivery::query()->create(['event_type' => 'manual_test', 'channel' => 'email', 'status' => 'failed', 'deduplication_key' => 'authorization-retry']);
        Livewire::test(ListNotificationDeliveries::class)
            ->callAction(TestAction::make('retry')->table($delivery))
            ->assertSuccessful();
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public static function settingsActions(): array
    {
        return [
            [NotificationSettingsPage::class, 'save', NotificationSetting::class],
            [NotificationSettingsPage::class, 'testEmail', NotificationSetting::class],
            [NotificationSettingsPage::class, 'testTelegram', NotificationSetting::class],
            [InstitutionSettingsPage::class, 'save', InstitutionSetting::class],
        ];
    }

    #[DataProvider('settingsActions')]
    public function test_settings_actions_recheck_authorization_after_role_revocation(string $page, string $action, string $model): void
    {
        Queue::fake();
        $user = $this->login('administrator');
        $component = Livewire::test($page);
        $before = $model::current()->getAttributes();
        $user->syncRoles(['viewer']);
        if ($action === 'save') {
            $component->call('save')->assertForbidden();
        } else {
            $component->mountAction(TestAction::make($action)->schemaComponent(true, 'content'))->assertForbidden();
        }
        $this->assertSame($before, $model::current()->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    private function incident(): ServiceIncident
    {
        return ServiceIncident::query()->create(['monitored_service_id' => MonitoredService::factory()->create(['is_active' => true])->id, 'started_at' => now(), 'confirmed_at' => now(), 'status' => 'open', 'incident_type' => 'down', 'severity' => 'high']);
    }
}
