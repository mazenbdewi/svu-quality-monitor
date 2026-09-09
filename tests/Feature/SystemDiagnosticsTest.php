<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemDiagnosticsPage;
use App\Models\MonitoredService;
use App\Models\NotificationSetting;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\BackupService;
use App\Services\SystemDiagnosticsService;
use App\Services\SystemHealthService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['is_active' => true])->assignRole('super_admin');
        config(['queue.default' => 'database', 'queue.failed.database' => 'sqlite', 'backup.directory' => sys_get_temp_dir(), 'diagnostics.minimum_free_bytes' => 0]);
        app(SystemHealthService::class)->recordSchedulerHeartbeat();
        app(SystemHealthService::class)->recordQueueHeartbeat();
        $this->mock(BackupService::class)->shouldReceive('health')->andReturn(['status' => 'healthy', 'age' => 1, 'success' => ['finished_at' => now()->toIso8601String()], 'failure' => null]);
    }

    private function snapshot(): array
    {
        return app(SystemDiagnosticsService::class)->snapshot();
    }

    public function test_healthy_system_is_ready_and_diagnostics_have_no_business_side_effects(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $auditCount = DB::table('audit_logs')->count();
        $this->assertSame('ready', $this->snapshot()['status']);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('notification_settings', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('service_checks', 0);
        Mail::assertNothingSent();
    }

    public function test_database_failure_is_contained_and_not_ready_without_raw_error(): void
    {
        $service = Mockery::mock(SystemDiagnosticsService::class, [app(SystemHealthService::class), app(BackupService::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('database')->andThrow(new \RuntimeException('secret-password-and-stack'));
        $result = $service->snapshot();
        $this->assertSame('not_ready', $result['status']);
        $this->assertSame('down', $result['checks']['database']['status']);
        $this->assertSame('healthy', $result['checks']['scheduler']['status']);
        $this->assertStringNotContainsString('secret-password-and-stack', json_encode($result));
    }

    public function test_scheduler_stale_uses_existing_heartbeat_rules(): void
    {
        Cache::put('system-health:scheduler:last-heartbeat', now()->subSeconds(config('monitoring.scheduler.down_after_seconds') + 1)->toIso8601String());
        $this->assertSame('not_ready', $this->snapshot()['status']);
        $this->assertSame(app(SystemHealthService::class)->schedulerStatus(), $this->snapshot()['checks']['scheduler']['status']);
    }

    public function test_actual_database_connection_failure_returns_not_ready(): void
    {
        $original = config('database.default');
        config(['database.connections.diagnostics_unavailable' => ['driver' => 'sqlite', 'database' => '/tmp/nonexistent-diagnostics-'.uniqid().'/database.sqlite'], 'database.default' => 'diagnostics_unavailable']);
        try {
            $result = $this->snapshot();
            $this->assertSame('not_ready', $result['status']);
            $this->assertSame('down', $result['checks']['database']['status']);
            $this->assertSame('healthy', $result['checks']['scheduler']['status']);
        } finally {
            config(['database.default' => $original]);
            DB::purge('diagnostics_unavailable');
        }
    }

    public function test_cache_failure_is_contained(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache-secret'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache-secret'));
        Cache::shouldReceive('forget')->andReturnTrue();
        $result = $this->snapshot();
        $this->assertSame('not_ready', $result['status']);
        $this->assertSame('down', $result['checks']['cache']['status']);
        $this->assertSame('healthy', $result['checks']['database']['status']);
        $this->assertStringNotContainsString('cache-secret', json_encode($result));
    }

    public function test_queue_stale_uses_existing_heartbeat_rules(): void
    {
        Cache::put('system-health:queue:last-heartbeat', now()->subSeconds(config('monitoring.queue.down_after_seconds') + 1)->toIso8601String());
        $this->assertSame('not_ready', $this->snapshot()['status']);
        $this->assertSame(app(SystemHealthService::class)->queueStatus(), $this->snapshot()['checks']['queue']['status']);
    }

    public function test_warning_heartbeats_need_attention(): void
    {
        foreach (['scheduler', 'queue'] as $component) {
            Cache::put("system-health:{$component}:last-heartbeat", now()->subSeconds(config("monitoring.{$component}.warning_after_seconds") + 1)->toIso8601String());
        }
        $result = $this->snapshot();
        $this->assertSame('attention', $result['status']);
        $this->assertSame('warning', $result['checks']['scheduler']['status']);
        $this->assertSame('warning', $result['checks']['queue']['status']);
    }

    public function test_pending_migrations_prevent_readiness(): void
    {
        DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();
        $result = $this->snapshot();
        $this->assertSame('not_ready', $result['status']);
        $this->assertSame(1, $result['checks']['migrations']['details']['pending']);
    }

    public function test_no_active_super_admin_prevents_readiness(): void
    {
        User::query()->update(['is_active' => false]);
        $this->assertSame('not_ready', $this->snapshot()['status']);
        $this->assertSame('down', $this->snapshot()['checks']['super_admin']['status']);
    }

    public function test_old_backup_uses_backup_service_and_needs_attention_even_when_down(): void
    {
        $this->mock(BackupService::class)->shouldReceive('health')->once()->andReturn(['status' => 'down', 'age' => 100, 'success' => null, 'failure' => null]);
        $result = $this->snapshot();
        $this->assertSame('attention', $result['status']);
        $this->assertSame('down', $result['checks']['backup']['status']);
    }

    public function test_failed_jobs_need_attention(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => 'diagnostics-failed-job', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'sensitive exception', 'failed_at' => now()]);
        $result = $this->snapshot();
        $this->assertSame('attention', $result['status']);
        $this->assertSame(1, $result['checks']['queue']['details']['failed_jobs']);
        $this->assertSame('warning', $result['checks']['queue']['status']);
    }

    public function test_unwritable_storage_is_not_ready(): void
    {
        $service = Mockery::mock(SystemDiagnosticsService::class, [app(SystemHealthService::class), app(BackupService::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('storageWritable')->andReturnFalse();
        $this->assertSame('not_ready', $service->snapshot()['status']);
    }

    public function test_disabled_telegram_is_healthy_and_incomplete_enabled_channel_warns(): void
    {
        $setting = NotificationSetting::create(['telegram_enabled' => false]);
        $this->assertSame('ready', $this->snapshot()['status']);
        $setting->update(['telegram_enabled' => true]);
        $this->assertSame('warning', $this->snapshot()['checks']['telegram']['status']);
        $this->assertSame('attention', $this->snapshot()['status']);
    }

    public function test_email_log_transport_is_not_delivery_ready_but_smtp_configuration_is(): void
    {
        NotificationSetting::create(['email_enabled' => true, 'email_recipients' => ['admin@example.org']]);
        config(['mail.default' => 'log']);
        $this->assertSame('warning', $this->snapshot()['checks']['email']['status']);
        config(['mail.default' => 'smtp', 'mail.from.address' => 'system@example.org']);
        $this->assertSame('healthy', $this->snapshot()['checks']['email']['status']);
    }

    public function test_missing_roles_and_audit_table_are_detected_without_repair(): void
    {
        Role::where('name', 'viewer')->delete();
        Schema::drop('audit_logs');
        $result = $this->snapshot();
        $this->assertSame('down', $result['checks']['rbac']['status']);
        $this->assertSame('down', $result['checks']['audit']['status']);
        $this->assertSame('not_ready', $result['status']);
    }

    public function test_disk_and_missing_backup_directory_warn_without_creating_it(): void
    {
        config(['diagnostics.minimum_free_bytes' => PHP_INT_MAX, 'backup.directory' => '/tmp/diagnostics-missing-'.uniqid()]);
        $result = $this->snapshot();
        $this->assertSame('warning', $result['checks']['disk']['status']);
        $this->assertSame('warning', $result['checks']['backup_ready']['status']);
        $this->assertDirectoryDoesNotExist(config('backup.directory'));
    }

    public function test_active_services_need_recent_automatic_not_manual_activity(): void
    {
        $service = MonitoredService::factory()->create(['is_active' => true, 'check_interval_minutes' => 1]);
        ServiceCheck::create(['monitored_service_id' => $service->id, 'source' => ServiceCheck::SOURCE_MANUAL, 'checked_at' => now(), 'is_success' => true]);
        $this->assertSame('warning', $this->snapshot()['checks']['monitoring']['status']);
        ServiceCheck::create(['monitored_service_id' => $service->id, 'source' => ServiceCheck::SOURCE_AUTOMATIC, 'checked_at' => now(), 'is_success' => true]);
        $this->assertSame('healthy', $this->snapshot()['checks']['monitoring']['status']);
    }

    public function test_production_debug_warns(): void
    {
        config(['app.env' => 'production', 'app.debug' => true]);
        $this->assertSame('warning', $this->snapshot()['checks']['application']['status']);
    }

    public function test_viewer_denied_and_operator_allowed_without_backup_permission(): void
    {
        $viewer = User::factory()->create()->assignRole('viewer');
        $this->actingAs($viewer)->get(SystemDiagnosticsPage::getUrl())->assertForbidden();
        $operator = User::factory()->create()->assignRole('operator');
        $this->actingAs($operator)->get(SystemDiagnosticsPage::getUrl())->assertOk()->assertSee(__('diagnostics.title'));
    }

    public function test_refresh_rechecks_health_and_authorization(): void
    {
        $operator = User::factory()->create()->assignRole('operator');
        $page = Livewire::actingAs($operator)->test(SystemDiagnosticsPage::class)->assertSet('diagnostics.status', 'ready');
        Cache::forget('system-health:scheduler:last-heartbeat');
        $page->call('refreshDiagnostics')->assertSet('diagnostics.status', 'not_ready');
        $operator->syncRoles('viewer');
        $page->call('refreshDiagnostics')->assertForbidden();
    }

    public function test_no_secrets_rendered_in_either_locale_or_livewire_state(): void
    {
        NotificationSetting::create(['telegram_enabled' => true, 'telegram_bot_token' => 'sensitive-telegram', 'telegram_chat_id' => 'sensitive-chat']);
        config(['database.connections.sqlite.password' => 'sensitive-db', 'mail.mailers.smtp.password' => 'sensitive-smtp']);
        $operator = User::factory()->create()->assignRole('operator');
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::actingAs($operator)->test(SystemDiagnosticsPage::class);
            foreach (['sensitive-telegram', 'sensitive-chat', 'sensitive-db', 'sensitive-smtp', config('app.key')] as $secret) {
                $page->assertDontSee($secret);
                $this->assertStringNotContainsString($secret, json_encode($page->get('diagnostics')));
            }
        }
    }
}
