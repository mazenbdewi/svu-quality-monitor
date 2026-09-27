<?php

namespace Tests\Feature;

use App\Models\InstitutionSetting;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\SlaMetric;
use App\Services\InstitutionTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstitutionTimezoneIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_iana_calendar_preserves_real_day_length_across_dst(): void
    {
        InstitutionSetting::current()->update(['timezone' => 'Europe/London']);
        $clock = app(InstitutionTimezone::class);
        $this->assertSame('2026-03-29 00:00:00', $clock->dayStart('2026-03-29')->toDateTimeString());
        $this->assertSame('2026-03-29 23:00:00', $clock->dayEnd('2026-03-29')->toDateTimeString());
        $this->assertEquals(23 * 3600, $clock->dayStart('2026-03-29')->diffInSeconds($clock->dayEnd('2026-03-29')));
        $this->assertEquals(25 * 3600, $clock->dayStart('2026-10-25')->diffInSeconds($clock->dayEnd('2026-10-25')));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_additive_migration_preserves_legacy_rows_and_does_not_invent_calculation_timezones(): void
    {
        $service = MonitoredService::factory()->create();
        InstitutionSetting::current();
        $fields = ['monitored_service_id' => $service->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-02'];
        ReliabilityMetric::create($fields + ['period_type' => 'daily', 'availability_percent' => 98]);
        SlaMetric::create($fields + ['target_percent' => 99.9, 'status' => 'met', 'calculated_at' => now()]);
        $migration = require database_path('migrations/2026_09_27_000001_add_institution_timezone.php');
        $migration->down();
        $before = [];
        foreach (['institution_settings', 'reliability_metrics', 'sla_metrics'] as $table) {
            $before[$table] = (array) DB::table($table)->first();
        }
        $migration->up();
        foreach ($before as $table => $original) {
            $after = (array) DB::table($table)->first();
            $this->assertSame($original, array_intersect_key($after, $original));
        }
        $this->assertSame('Asia/Damascus', InstitutionSetting::current()->timezone);
        $this->assertNull(ReliabilityMetric::sole()->analysis_timezone);
        $this->assertNull(SlaMetric::sole()->analysis_timezone);
        InstitutionSetting::current()->update(['timezone' => 'Asia/Dubai']);
        $this->assertSame('UTC', app(InstitutionTimezone::class)->forRecord(ReliabilityMetric::sole()));
        $this->assertNull(ReliabilityMetric::sole()->analysis_timezone);
    }
}
