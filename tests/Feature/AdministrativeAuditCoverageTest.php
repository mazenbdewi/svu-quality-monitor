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
use App\Models\AuditLog;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\NotificationDeliveryRetryService;
use App\Services\UserAdministrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrativeAuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('administrator');
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Queue::fake();
    }

    private function audit(string $event): AuditLog
    {
        $log = AuditLog::where('event', $event)->sole();
        $this->assertSame($this->actor->id, $log->actor_id);
        $this->assertNotNull($log->created_at);
        $this->assertNotEmpty($log->description);

        return $log;
    }

    private function assertNoSecrets(array $secrets): void
    {
        $json = AuditLog::all()->toJson(JSON_UNESCAPED_SLASHES).json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_SLASHES);
        foreach ($secrets as $secret) {
            $this->assertNotEmpty($secret);
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_service_create_is_audited(): void
    {
        Livewire::test(CreateMonitoredService::class)->fillForm(['name' => 'Audited service', 'url' => 'https://example.test', 'check_type' => 'http', 'expected_status_code' => 200, 'check_interval_minutes' => 5, 'warning_response_ms' => 1500, 'critical_response_ms' => 3000, 'is_active' => true])->call('create')->assertHasNoFormErrors();
        $log = $this->audit('service.created');
        $this->assertSame('Audited service', $log->after['name']);
        $this->assertSame('http', $log->after['check_type']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function serviceUpdates(): array
    {
        return [
            [['name' => 'Renamed service'], 'service.updated'],
            [['is_active' => false], 'service.disabled'],
            [['sla_enabled' => true, 'sla_target_percent' => 99.9], 'service.sla_updated'],
            [['name' => 'Renamed with SLA', 'sla_enabled' => true, 'sla_target_percent' => 99.9], 'service.updated'],
        ];
    }

    #[DataProvider('serviceUpdates')]
    public function test_service_updates_have_one_log(array $data, string $event): void
    {
        $service = MonitoredService::factory()->create();
        Livewire::test(EditMonitoredService::class, ['record' => $service->id])->fillForm($data)->call('save')->assertHasNoFormErrors();
        $log = $this->audit($event);
        $this->assertSame($service->id, $log->auditable_id);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_service_enable_and_delete_are_audited(): void
    {
        $service = MonitoredService::factory()->inactive()->create();
        Livewire::test(EditMonitoredService::class, ['record' => $service->id])->fillForm(['is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->audit('service.enabled');
        Livewire::test(EditMonitoredService::class, ['record' => $service->id])->callAction('delete');
        $this->assertModelMissing($service);
        $this->audit('service.deleted');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_bulk_delete_audits_each_deleted_service_once(): void
    {
        $services = MonitoredService::factory()->count(2)->create();
        Livewire::test(ListMonitoredServices::class)
            ->selectTableRecords($services->modelKeys())
            ->callAction(TestAction::make('delete')->table()->bulk());
        $this->assertDatabaseCount('monitored_services', 0);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertEqualsCanonicalizing($services->modelKeys(), AuditLog::where('event', 'service.deleted')->pluck('auditable_id')->all());
    }

    public function test_maintenance_service_assignment_diff_is_captured_before_relationship_save(): void
    {
        $services = MonitoredService::factory()->count(2)->create();
        $window = MaintenanceWindow::create(['name' => 'Window', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'applies_to_all_services' => false]);
        $window->monitoredServices()->attach($services[0]);
        Livewire::test(EditMaintenanceWindow::class, ['record' => $window->id])->fillForm(['monitoredServices' => [$services[1]->id]])->call('save')->assertHasNoFormErrors();
        $log = $this->audit('maintenance.updated');
        $this->assertSame([$services[0]->id], $log->before['service_ids']);
        $this->assertSame([$services[1]->id], $log->after['service_ids']);
    }

    public function test_monitoring_configuration_logs_only_marker_not_plaintext_or_ciphertext(): void
    {
        $service = MonitoredService::factory()->create(['check_type' => 'api', 'check_config' => ['headers' => ['Authorization' => 'Bearer synthetic-old-secret']]]);
        $oldCipher = $service->getRawOriginal('check_config');
        Livewire::test(EditMonitoredService::class, ['record' => $service->id])->fillForm(['check_config' => ['headers' => ['Authorization' => 'Bearer synthetic-new-secret', 'X-API-Key' => 'synthetic-api-key'], 'body' => ['api_token' => 'synthetic-body-secret']]])->call('save')->assertHasNoFormErrors();
        $log = $this->audit('service.monitoring_config_updated');
        $this->assertTrue($log->context['sensitive_monitoring_configuration_changed']);
        $this->assertArrayNotHasKey('check_config', $log->after);
        $this->assertNoSecrets(['synthetic-old-secret', 'synthetic-new-secret', 'synthetic-api-key', 'synthetic-body-secret', $oldCipher, $service->fresh()->getRawOriginal('check_config')]);
    }

    public function test_maintenance_create_update_delete_and_service_ids(): void
    {
        $service = MonitoredService::factory()->create();
        Livewire::test(CreateMaintenanceWindow::class)->fillForm(['name' => 'Audited window', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString(), 'applies_to_all_services' => false, 'monitoredServices' => [$service->id]])->call('create')->assertHasNoFormErrors();
        $window = MaintenanceWindow::sole();
        $this->assertSame([$service->id], $this->audit('maintenance.created')->after['service_ids']);
        Livewire::test(EditMaintenanceWindow::class, ['record' => $window->id])->fillForm(['name' => 'Renamed window'])->call('save')->assertHasNoFormErrors();
        $this->audit('maintenance.updated');
        Livewire::test(EditMaintenanceWindow::class, ['record' => $window->id])->callAction('delete');
        $this->audit('maintenance.deleted');
        $this->assertModelMissing($window);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_user_lifecycle_and_passwords_are_safely_audited(): void
    {
        $service = app(UserAdministrationService::class);
        $user = $service->create($this->actor, ['name' => 'Audited User', 'email' => 'audited@example.test', 'password' => 'synthetic-initial-password', 'role' => 'viewer', 'is_active' => true]);
        $this->audit('user.created');
        $oldHash = $user->password;
        $service->update($this->actor, $user, ['name' => 'Renamed User']);
        $this->audit('user.updated');
        $service->syncRoles($this->actor, $user, ['operator']);
        $role = $this->audit('user.role_changed');
        $this->assertSame(['viewer'], $role->before['roles']);
        $this->assertSame(['operator'], $role->after['roles']);
        $service->deactivate($this->actor, $user);
        $this->audit('user.deactivated');
        $service->activate($this->actor, $user);
        $this->audit('user.activated');
        $service->update($this->actor, $user, ['password' => 'synthetic-new-password']);
        $this->assertTrue($this->audit('user.password_changed_by_admin')->context['password_changed']);
        $this->assertDatabaseCount('audit_logs', 6);
        $this->assertNoSecrets(['synthetic-initial-password', 'synthetic-new-password', $oldHash, $user->fresh()->password]);
    }

    public function test_notification_token_configure_replace_remove_and_tests(): void
    {
        foreach (['configured' => 'synthetic-telegram-one', 'replaced' => 'synthetic-telegram-two', 'removed' => null] as $action => $token) {
            Livewire::test(NotificationSettingsPage::class)->fillForm(['telegram_bot_token' => $token ?? '', 'remove_telegram_token' => $token === null, 'telegram_enabled' => false, 'email_enabled' => true, 'email_recipients' => ['ops@example.test']])->call('save')->assertHasNoFormErrors();
            $log = AuditLog::latest('id')->firstOrFail();
            $this->assertSame('notification.settings_updated', $log->event);
            $this->assertSame($action, $log->context['telegram_token_action']);
            if ($token !== null) {
                $this->assertNoSecrets([$token, NotificationSetting::current()->getRawOriginal('telegram_bot_token')]);
            }
        }
        $this->assertNull(NotificationSetting::current()->telegram_bot_token);
        $setting = NotificationSetting::current();
        $setting->update(['telegram_enabled' => true, 'telegram_bot_token' => 'synthetic-test-token', 'telegram_chat_id' => '123']);
        foreach (['telegram' => 'testTelegram', 'email' => 'testEmail'] as $channel => $action) {
            Livewire::test(NotificationSettingsPage::class)->callAction(TestAction::make($action)->schemaComponent(true, 'content'))->assertNotified();
            $this->audit('notification.'.$channel.'_test_requested');
        }
        $this->assertNoSecrets(['synthetic-test-token', $setting->getRawOriginal('telegram_bot_token')]);
    }

    public function test_manual_retry_is_audited_but_non_manual_retry_is_not(): void
    {
        $delivery = NotificationDelivery::create(['event_type' => 'incident_confirmed', 'channel' => 'email', 'status' => 'failed', 'deduplication_key' => 'audit-retry']);
        app(NotificationDeliveryRetryService::class)->retry($delivery);
        $this->assertDatabaseCount('audit_logs', 0);
        $delivery->refresh()->update(['status' => 'failed']);
        Livewire::test(ListNotificationDeliveries::class)->callAction(TestAction::make('retry')->table($delivery));
        $log = $this->audit('notification.retry_requested');
        $this->assertSame($delivery->id, $log->auditable_id);
        $this->assertSame('email', $log->context['channel']);
        $this->assertSame('incident_confirmed', $log->context['event_type']);
    }

    public function test_institution_name_and_logo_are_audited_without_signed_url_secret(): void
    {
        Livewire::test(InstitutionSettingsPage::class)->fillForm(['institution_name' => 'Audited institution', 'institution_logo' => 'https://example.test/logo.png?token=synthetic-signed-logo'])->call('save')->assertHasNoFormErrors();
        $log = $this->audit('institution.updated');
        $this->assertSame('Audited institution', $log->after['institution_name']);
        $this->assertTrue($log->context['logo_changed']);
        $this->assertNoSecrets(['synthetic-signed-logo']);
    }

    public function test_excel_report_generation_has_metadata_not_content(): void
    {
        $service = MonitoredService::factory()->create(['name' => 'synthetic-report-content']);
        ServiceCheck::create(['monitored_service_id' => $service->id, 'checked_at' => now(), 'is_success' => true, 'is_slow' => false]);
        Livewire::test(ReportsPage::class)->fillForm(['report_type' => 'service_checks', 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()])->call('export')->assertFileDownloaded();
        $log = $this->audit('report.generated');
        $this->assertSame('service_checks', $log->context['report_type']);
        $this->assertSame(now()->toDateString(), $log->context['period']['date_from']);
        $this->assertSame('excel', $log->context['format']);
        $this->assertNoSecrets(['synthetic-report-content']);
    }

    public function test_denied_user_change_and_outer_rollback_produce_no_success_audit(): void
    {
        $target = User::factory()->create();
        $target->assignRole('super_admin');
        try {
            app(UserAdministrationService::class)->update($this->actor, $target, ['name' => 'Denied']);
            $this->fail('Expected denial');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }
        DB::beginTransaction();
        app(UserAdministrationService::class)->create($this->actor, ['name' => 'Rolled back', 'email' => 'rollback@example.test', 'password' => 'synthetic-password', 'role' => 'viewer']);
        $this->assertDatabaseCount('audit_logs', 1);
        DB::rollBack();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    }

    public function test_failed_validation_does_not_audit(): void
    {
        Livewire::test(CreateMonitoredService::class)->fillForm(['name' => '', 'url' => ''])->call('create')->assertHasFormErrors(['name', 'url']);
        Livewire::test(NotificationSettingsPage::class)->fillForm(['email_enabled' => true, 'email_recipients' => []])->call('save')->assertHasFormErrors();
        Livewire::test(NotificationSettingsPage::class)->callAction(TestAction::make('testTelegram')->schemaComponent(true, 'content'))->assertNotified();
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
