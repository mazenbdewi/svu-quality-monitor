<?php

namespace Tests\Feature;

use App\Exports\Reports\Sheets\ServiceChecksSheet;
use App\Filament\Pages\InstitutionSettingsPage;
use App\Models\AuditLog;
use App\Models\ControlChart;
use App\Models\InstitutionSetting;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\SlaMetric;
use App\Models\User;
use App\Reports\ComprehensivePdfReport;
use App\Reports\ExecutiveMonthlyPdfReport;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\InstitutionTimezone;
use App\Services\NotificationDispatcher;
use App\Services\SpcAnalysisWindow;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstitutionSettingsTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $role = 'administrator'): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public function test_database_default_is_damascus_and_obsolete_config_cannot_override_it(): void
    {
        config(['monitoring.institution_timezone' => 'Europe/London', 'monitoring.spc.analysis_timezone' => 'Asia/Dubai']);
        $this->assertSame('Asia/Damascus', app(InstitutionTimezone::class)->name());
        $this->assertSame('Asia/Damascus', InstitutionSetting::current()->timezone);
        InstitutionSetting::current()->update(['timezone' => 'Asia/Riyadh']);
        $this->assertSame('Asia/Riyadh', app(InstitutionTimezone::class)->name());
        $this->assertSame('Asia/Riyadh', app(SpcAnalysisWindow::class)->timezone());
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_admin_can_save_valid_timezone_with_warning_and_audit(): void
    {
        $actor = $this->admin();
        Carbon::setTestNow('2026-12-31 22:30:00');
        Livewire::test(InstitutionSettingsPage::class)
            ->assertSee(__('institution_timezone.warning'))
            ->fillForm(['timezone' => 'Asia/Dubai'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Asia/Dubai', InstitutionSetting::current()->timezone);
        $audit = AuditLog::where('event', 'institution.timezone_changed')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame(['timezone' => 'Asia/Damascus'], $audit->before);
        $this->assertSame(['timezone' => 'Asia/Dubai'], $audit->after);
        $this->assertSame('2026-12-31 22:30:00', $audit->getRawOriginal('created_at'));
        $this->assertTrue($audit->context['measurement_configuration_changed']);
        Livewire::test(InstitutionSettingsPage::class)->call('save')->assertHasNoFormErrors();
        $this->assertSame(1, AuditLog::where('event', 'institution.timezone_changed')->count());
    }

    public function test_invalid_timezone_is_rejected_without_audit_or_setting_change(): void
    {
        $this->admin();
        Livewire::test(InstitutionSettingsPage::class)->fillForm(['timezone' => 'UTC+3'])->call('save')->assertHasFormErrors(['timezone']);
        $this->assertSame('Asia/Damascus', InstitutionSetting::current()->timezone);
        $this->assertSame(0, AuditLog::where('event', 'institution.timezone_changed')->count());
    }

    public static function nonManagers(): array
    {
        return [['viewer'], ['operator']];
    }

    #[DataProvider('nonManagers')]
    public function test_viewer_and_operator_cannot_save_timezone(string $role): void
    {
        $user = $this->admin($role);
        $user->givePermissionTo('institution.view');
        Livewire::test(InstitutionSettingsPage::class)->set('data.timezone', 'Asia/Dubai')->call('save')->assertForbidden();
        $this->assertSame('Asia/Damascus', InstitutionSetting::current()->timezone);
    }

    public function test_revoked_permission_is_rechecked_at_save(): void
    {
        $user = $this->admin();
        $page = Livewire::test(InstitutionSettingsPage::class)->fillForm(['timezone' => 'Asia/Dubai']);
        $user->syncRoles(['viewer']);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $page->call('save')->assertForbidden();
    }

    public function test_display_and_report_filters_cross_local_midnight_without_mutating_utc(): void
    {
        InstitutionSetting::current()->update(['timezone' => 'Asia/Dubai']);
        Carbon::setTestNow('2027-01-02');
        $service = MonitoredService::factory()->create();
        foreach (['2026-12-31 19:59:59', '2026-12-31 20:00:00', '2027-01-01 19:59:59', '2027-01-01 20:00:00'] as $time) {
            $service->serviceChecks()->create(['checked_at' => $time, 'is_success' => true, 'is_slow' => false]);
        }
        $sheet = new ServiceChecksSheet(['date_from' => '2027-01-01', 'date_to' => '2027-01-01']);
        $this->assertSame(2, $sheet->query()->count());
        $check = $service->serviceChecks()->where('checked_at', '2026-12-31 20:00:00')->firstOrFail();
        $before = $check->checked_at->toIso8601String();
        $this->assertSame('2027-01-01 00:00:00', TextColumn::make('checked_at')->dateTime('Y-m-d H:i:s')->record($check)->formatState($check->checked_at));
        $this->assertSame($before, $check->checked_at->toIso8601String());
        $this->assertSame('2026-12-31 20:00:00', $check->getRawOriginal('checked_at'));
        $report = new ComprehensivePdfReport;
        $this->assertSame('2027-01-01 00:00:00', $report->dateTime($check->checked_at));
        $monthly = (new ExecutiveMonthlyPdfReport('2027-01-01'))->data();
        $this->assertSame('2026-12-31 20:00:00', $monthly['periodStart']->copy()->utc()->toDateTimeString());
    }

    public function test_new_calculations_use_setting_and_old_calendars_survive_later_change(): void
    {
        InstitutionSetting::current()->update(['timezone' => 'Asia/Dubai']);
        Carbon::setTestNow('2026-12-31 20:25:00'); // January 1 locally.
        MonitoredService::factory()->create(['created_at' => '2026-01-01', 'is_active' => true, 'sla_enabled' => true]);
        $this->mock(NotificationDispatcher::class)->shouldReceive('sla')->once();
        $this->artisan('reliability:calculate')->assertSuccessful();
        $this->artisan('sla:calculate')->assertSuccessful();
        $this->artisan('control-charts:calculate --chart=i_chart')->assertSuccessful();
        $reliability = ReliabilityMetric::sole();
        $sla = SlaMetric::sole();
        $chart = ControlChart::sole();
        $this->assertSame('2026-12-30 20:00:00', $reliability->getRawOriginal('period_start'));
        $this->assertSame('2026-12-31 20:00:00', $reliability->getRawOriginal('period_end'));
        $this->assertSame('2026-11-30 20:00:00', $sla->getRawOriginal('period_start'));
        $this->assertSame('2026-12-31 19:59:59', $sla->getRawOriginal('period_end'));
        $this->assertSame($reliability->getRawOriginal('period_start'), $chart->getRawOriginal('period_start'));
        $before = [$reliability->getRawOriginal(), $sla->getRawOriginal(), $chart->getRawOriginal()];
        InstitutionSetting::current()->update(['timezone' => 'Europe/London']);
        foreach ([$reliability, $sla, $chart] as $index => $row) {
            $this->assertSame('Asia/Dubai', $row->fresh()->analysis_timezone);
            $this->assertSame($before[$index], $row->fresh()->getRawOriginal());
            $this->assertSame('Asia/Dubai', app(InstitutionTimezone::class)->forRecord($row));
        }
    }

    public function test_approved_baseline_is_unchanged_after_institution_timezone_change(): void
    {
        $actor = $this->admin();
        Carbon::setTestNow('2026-09-03');
        $service = MonitoredService::factory()->create(['created_at' => '2026-01-01', 'check_interval_minutes' => 60]);
        foreach ([100, 110, 130, 160] as $i => $v) {
            $service->serviceChecks()->create(['checked_at' => Carbon::parse('2026-09-01')->addHours($i), 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'response_time_ms' => $v, 'is_during_maintenance' => false])->forceFill(['created_at' => Carbon::parse('2026-09-01')->addHours($i), 'updated_at' => Carbon::parse('2026-09-01')->addHours($i)])->save();
        }
        $engine = app(PhaseOneBaselineService::class);
        $baseline = $engine->create($service, 'i_chart', '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'Asia/Damascus', $actor);
        $engine->review($baseline, $actor, 'Accepted isolated test fixture', true);
        $engine->approve($baseline, $actor, 'Isolated test');
        $before = $baseline->fresh()->toArray();
        $parameters = $engine->reproduce($baseline->fresh());
        InstitutionSetting::current()->update(['timezone' => 'Europe/London']);
        $this->assertSame($before, $baseline->fresh()->toArray());
        $this->assertSame($parameters, $engine->reproduce($baseline->fresh()));
    }

    public function test_fresh_scheduler_process_reads_changed_database_setting_without_env_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'institution-schedule-');
        try {
            $db = new \PDO('sqlite:'.$path);
            $db->exec('CREATE TABLE institution_settings (id INTEGER PRIMARY KEY, timezone TEXT)');
            $db->exec("INSERT INTO institution_settings VALUES (1, 'Asia/Damascus')");
            $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'INSTITUTION_TIMEZONE' => 'Asia/Riyadh', 'SPC_ANALYSIS_TIMEZONE' => 'Asia/Riyadh'];
            $read = function () use ($env): array {
                $process = new Process([PHP_BINARY, 'artisan', 'schedule:list', '--json', '--timezone=UTC'], base_path(), $env);
                $process->mustRun();

                return collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->keyBy('command')->all();
            };
            $a = $read();
            $this->assertSame('10 21 * * *', $a['php artisan reliability:calculate --period=daily']['expression']);
            $db->exec("UPDATE institution_settings SET timezone = 'Asia/Dubai'");
            $b = $read();
            $this->assertSame('10 20 * * *', $b['php artisan reliability:calculate --period=daily']['expression']);
            $this->assertSame('25 20 * * *', $b['php artisan control-charts:calculate --period=daily']['expression']);
            $this->assertSame('* * * * *', $b['php artisan spc:evaluate']['expression']);
            $this->assertCount(9, $b);
        } finally {
            unset($db);
            unlink($path);
        }
    }
}
