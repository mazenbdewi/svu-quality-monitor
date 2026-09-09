<?php

namespace Tests\Feature;

use App\Filament\Pages\InstitutionSettingsPage;
use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Pages\ReportsPage;
use App\Filament\Resources\MaintenanceWindows\Pages\EditMaintenanceWindow;
use App\Filament\Resources\MonitoredServices\Pages\EditMonitoredService;
use App\Models\AuditLog;
use App\Models\InstitutionSetting;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\NotificationSetting;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use App\Models\User;
use App\Monitoring\CheckResult;
use App\Services\AuditLogger;
use App\Services\IncidentAcknowledgementService;
use App\Services\ServiceCheckRunner;
use App\Services\SystemHealthService;
use Barryvdh\DomPDF\PDF;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Queue::fake();
    }

    public function test_automatic_monitoring_confirmation_recovery_and_heartbeats_do_not_audit(): void
    {
        $service = MonitoredService::factory()->create();
        foreach ([false, false, true, true] as $success) {
            app(ServiceCheckRunner::class)->persist($service, new CheckResult($success, now(), statusCode: $success ? 200 : 500, responseTimeMs: 10), 'automatic');
            $this->assertDatabaseCount('audit_logs', 0);
        }
        $this->assertSame('closed', ServiceIncident::sole()->status);
        app(SystemHealthService::class)->recordSchedulerHeartbeat();
        app(SystemHealthService::class)->recordQueueHeartbeat();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_scheduled_calculations_do_not_create_human_audit(): void
    {
        $service = MonitoredService::factory()->create(['sla_enabled' => true, 'sla_target_percent' => 99.9]);
        foreach (['reliability:calculate', 'sla:calculate', 'control-charts:calculate'] as $command) {
            $this->artisan($command, ['--service' => $service->id])->assertSuccessful();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_acknowledgement_uses_explicit_actor_once_without_authenticated_session(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('operator');
        $service = MonitoredService::factory()->create();
        $incident = ServiceIncident::create(['monitored_service_id' => $service->id, 'started_at' => now(), 'confirmed_at' => now(), 'status' => 'open', 'incident_type' => 'down', 'severity' => 'high']);
        $acknowledger = app(IncidentAcknowledgementService::class);
        $acknowledger->acknowledge($actor, $incident);
        $acknowledger->acknowledge($actor, $incident);
        $log = AuditLog::sole();
        $this->assertSame('incident.acknowledged', $log->event);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame($incident->id, $log->auditable_id);
        $this->assertSame($service->id, $log->context['service_id']);
    }

    public function test_successful_login_has_actor_request_context_and_no_credentials(): void
    {
        $user = User::factory()->create(['password' => 'synthetic-login-password']);
        request()->server->set('REMOTE_ADDR', '192.0.2.15');
        request()->headers->set('User-Agent', 'SyntheticBrowser/1.0');
        Event::dispatch(new Login('web', $user, false));
        $log = AuditLog::sole();
        $this->assertSame('user.logged_in', $log->event);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('192.0.2.15', $log->ip_address);
        $this->assertSame('SyntheticBrowser/1.0', $log->user_agent);
        $this->assertNotNull($user->fresh()->last_login_at);
        foreach (['synthetic-login-password', $user->password, $user->remember_token] as $secret) {
            $this->assertStringNotContainsString($secret, $log->toJson().json_encode($log->getAttributes()));
        }
    }

    public function test_executive_monthly_pdf_is_audited_after_rendering_without_content(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('operator');
        $this->actingAs($actor);
        $service = MonitoredService::factory()->create();
        SlaMetric::create(['monitored_service_id' => $service->id, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'target_percent' => 99.9, 'eligible_observation_seconds' => 1000, 'planned_maintenance_seconds' => 0, 'unplanned_downtime_seconds' => 0, 'availability_percent' => 100, 'allowed_downtime_seconds' => 1, 'error_budget_consumed_seconds' => 0, 'error_budget_remaining_seconds' => 1, 'error_budget_consumed_percent' => 0, 'status' => 'met', 'calculated_at' => now()]);
        $pdf = \Mockery::mock(PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4')->andReturnSelf();
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-synthetic-binary-report');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andReturn($pdf);
        Livewire::test(ReportsPage::class)->fillForm(['report_type' => 'executive_monthly', 'export_format' => 'pdf', 'report_month' => now()->startOfMonth()->toDateString()])->call('export')->assertFileDownloaded();
        $log = AuditLog::sole();
        $this->assertSame('report.generated', $log->event);
        $this->assertSame('executive_monthly', $log->context['report_type']);
        $this->assertSame(now()->startOfMonth()->toDateString(), $log->context['period']['report_month']);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertStringNotContainsString('%PDF-synthetic-binary-report', $log->toJson());
    }

    public static function transactionPages(): array
    {
        return [
            [EditMonitoredService::class, 'service'],
            [EditMaintenanceWindow::class, 'maintenance'],
            [NotificationSettingsPage::class, 'notification'],
            [InstitutionSettingsPage::class, 'institution'],
        ];
    }

    #[DataProvider('transactionPages')]
    public function test_audit_failure_rolls_back_business_change(string $page, string $type): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('administrator');
        $this->actingAs($actor);
        [$record, $change] = match ($type) {
            'service' => [MonitoredService::factory()->create(), ['name' => 'Must roll back']],
            'maintenance' => [MaintenanceWindow::create(['name' => 'Window', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'applies_to_all_services' => true]), ['name' => 'Must roll back']],
            'notification' => [NotificationSetting::current(), ['email_enabled' => true, 'email_recipients' => ['ops@example.test']]],
            'institution' => [InstitutionSetting::current(), ['institution_name' => 'Must roll back']],
        };
        $before = $record->fresh()->getAttributes();
        $component = Livewire::test($page, ['record' => $record->id])->fillForm($change);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->withoutExceptionHandling();
        try {
            $component->call('save');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
