<?php

namespace Tests\Feature;

use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\SlaMetric;
use App\Services\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstitutionTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'monitoring.institution_timezone' => 'Asia/Damascus', 'monitoring.spc.analysis_timezone' => 'Asia/Damascus']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_commands_share_yesterday_in_damascus_and_store_utc_boundaries(): void
    {
        Carbon::setTestNow('2026-09-26 21:25:00'); // September 27, 00:25 in Damascus.
        $service = MonitoredService::factory()->create(['created_at' => '2026-09-01', 'is_active' => true]);
        foreach (['2026-09-25 20:59:59', '2026-09-25 21:00:00', '2026-09-26 20:59:59', '2026-09-26 21:00:00'] as $at) {
            $service->serviceChecks()->create(['checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true, 'is_slow' => false, 'response_time_ms' => 100, 'is_during_maintenance' => false]);
        }
        $this->artisan('reliability:calculate')->assertSuccessful();
        $this->artisan('control-charts:calculate --chart=i_chart')->assertSuccessful();
        foreach ([ReliabilityMetric::firstOrFail(), ControlChart::firstOrFail()] as $row) {
            $this->assertSame('2026-09-25 21:00:00', $row->getRawOriginal('period_start'));
            $this->assertSame('2026-09-26 21:00:00', $row->getRawOriginal('period_end'));
        }
        $this->assertSame(2, ReliabilityMetric::first()->total_checks);
        $this->assertSame(2, ControlChart::first()->points()->count());
        $this->assertSame('UTC', config('app.timezone'));
    }

    public static function reliabilityPeriods(): array
    {
        return [
            ['daily', '2026-09-26', '2026-09-25 21:00:00', '2026-09-26 21:00:00'],
            ['daily', '2026-09-25T22:00:00Z', '2026-09-25 21:00:00', '2026-09-26 21:00:00'],
            ['weekly', '2026-09-26', '2026-09-20 21:00:00', '2026-09-27 21:00:00'],
            ['monthly', '2026-09-26', '2026-08-31 21:00:00', '2026-09-30 21:00:00'],
        ];
    }

    #[DataProvider('reliabilityPeriods')]
    public function test_explicit_reliability_period_uses_institution_calendar(string $period, string $date, string $start, string $end): void
    {
        Carbon::setTestNow('2026-10-02');
        MonitoredService::factory()->create(['created_at' => '2026-08-01', 'is_active' => true]);
        $this->artisan('reliability:calculate', ['--period' => $period, '--date' => $date])->assertSuccessful();
        $row = ReliabilityMetric::firstOrFail();
        $this->assertSame($start, $row->getRawOriginal('period_start'));
        $this->assertSame($end, $row->getRawOriginal('period_end'));
    }

    public static function slaMonths(): array
    {
        return [
            ['2026-09-30 21:20:00', null, '2026-08-31 21:00:00', '2026-09-30 20:59:59'],
            ['2026-10-01 21:20:00', null, '2026-09-30 21:00:00', '2026-10-31 20:59:59'],
            ['2026-12-31 21:20:00', null, '2026-11-30 21:00:00', '2026-12-31 20:59:59'],
            ['2026-10-02 21:20:00', '2026-09-30T22:00:00Z', '2026-09-30 21:00:00', '2026-10-31 20:59:59'],
        ];
    }

    #[DataProvider('slaMonths')]
    public function test_sla_selects_month_of_local_yesterday_or_explicit_date(string $now, ?string $date, string $start, string $end): void
    {
        Carbon::setTestNow($now);
        MonitoredService::factory()->create(['created_at' => '2026-08-01', 'is_active' => true, 'sla_enabled' => true, 'sla_target_percent' => 99.9]);
        $this->mock(NotificationDispatcher::class)->shouldReceive('sla')->once();
        $this->artisan('sla:calculate', $date ? ['--date' => $date] : [])->assertSuccessful();
        $row = SlaMetric::firstOrFail();
        $this->assertSame($start, $row->getRawOriginal('period_start'));
        $this->assertSame($end, $row->getRawOriginal('period_end'));
        $this->assertSame('100.000000', $row->availability_percent);
    }

    public function test_scheduler_daily_events_are_due_at_local_times_and_recurring_tasks_are_unchanged(): void
    {
        $events = collect(app(Schedule::class)->events());
        foreach ([
            'reliability:calculate' => ['10 0 * * *', '2026-09-26 21:10:00'],
            'sla:calculate' => ['20 0 * * *', '2026-09-26 21:20:00'],
            'control-charts:calculate' => ['25 0 * * *', '2026-09-26 21:25:00'],
            'backup:create' => ['0 2 * * *', '2026-09-26 23:00:00'],
            'backup:cleanup' => ['0 3 * * *', '2026-09-27 00:00:00'],
        ] as $command => [$expression, $utc]) {
            $matches = $events->filter(fn ($e) => str_contains($e->command ?? '', $command));
            $this->assertCount(1, $matches);
            $event = $matches->first();
            $this->assertSame('Asia/Damascus', $event->timezone);
            $this->assertSame($expression, $event->expression);
            Carbon::setTestNow($utc);
            $this->assertTrue($event->isDue($this->app));
            Carbon::setTestNow(Carbon::parse($utc)->addMinute());
            $this->assertFalse($event->isDue($this->app));
        }
        foreach (['services:check-due', 'spc:evaluate', 'system:check-background-health'] as $command) {
            $event = $events->first(fn ($e) => str_contains($e->command ?? '', $command));
            $this->assertSame($command === 'system:check-background-health' ? '*/5 * * * *' : '* * * * *', $event->expression);
        }
        $heartbeat = $events->first(fn ($e) => $e->description === 'system-health:scheduler-heartbeat');
        $this->assertSame('* * * * *', $heartbeat->expression);
        $this->assertCount(9, $events);
    }
}
