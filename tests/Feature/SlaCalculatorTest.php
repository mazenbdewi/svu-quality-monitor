<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\ServiceIncident;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_full_availability_meets_a_99_point_9_sla(): void
    {
        $metric = $this->calculate($this->service(), '2026-09-01 00:00:00', '2026-09-02 00:00:00');

        $this->assertSame('met', $metric->status);
        $this->assertSame('100.000000', $metric->availability_percent);
        $this->assertEquals(86.4, (float) $metric->allowed_downtime_seconds);
    }

    public function test_confirmed_incident_below_target_is_breached_and_can_exceed_budget(): void
    {
        $service = $this->service();
        $this->incident($service, '2026-09-01 00:00:00', '2026-09-01 01:00:00');
        $metric = $this->calculate($service, '2026-09-01 00:00:00', '2026-09-02 00:00:00');

        $this->assertSame('breached', $metric->status);
        $this->assertGreaterThan(100, (float) $metric->error_budget_consumed_percent);
        $this->assertLessThan(0, (float) $metric->error_budget_remaining_seconds);
    }

    public function test_maintenance_and_its_overlap_with_an_incident_are_excluded(): void
    {
        $service = $this->service();
        MaintenanceWindow::query()->create(['name' => 'Maintenance', 'applies_to_all_services' => true, 'starts_at' => '2026-09-01 00:10:00', 'ends_at' => '2026-09-01 00:30:00']);
        $this->incident($service, '2026-09-01 00:00:00', '2026-09-01 01:00:00');
        $metric = $this->calculate($service, '2026-09-01 00:00:00', '2026-09-01 01:00:00');

        $this->assertSame(1200, $metric->planned_maintenance_seconds);
        $this->assertSame(2400, $metric->unplanned_downtime_seconds);
    }

    public function test_open_confirmed_incident_is_measured_until_now_and_current_month_excludes_future_time(): void
    {
        Carbon::setTestNow('2026-09-01 10:20:00');
        $service = $this->service();
        $this->incident($service, '2026-09-01 10:00:00');
        $metric = $this->calculate($service, '2026-09-01 00:00:00', '2026-09-30 23:59:59');

        $this->assertSame(37200, $metric->eligible_observation_seconds);
        $this->assertSame(1200, $metric->unplanned_downtime_seconds);
    }

    public function test_pending_or_unconfirmed_incidents_do_not_count_as_downtime(): void
    {
        $service = $this->service();
        ServiceIncident::query()->create(['monitored_service_id' => $service->id, 'started_at' => '2026-09-01 00:00:00', 'ended_at' => '2026-09-01 01:00:00', 'status' => 'open']);
        $metric = $this->calculate($service, '2026-09-01 00:00:00', '2026-09-01 02:00:00');

        $this->assertSame(0, $metric->unplanned_downtime_seconds);
    }

    public function test_no_data_and_disabled_sla_are_not_breached_and_recalculation_is_idempotent(): void
    {
        $service = $this->service(['sla_enabled' => false]);
        $disabled = $this->calculate($service, '2026-09-01', '2026-09-02');
        $this->assertSame('not_configured', $disabled->status);

        $service->update(['sla_enabled' => true]);
        Carbon::setTestNow('2026-09-01 00:00:00');
        $noData = $this->calculate($service, '2026-09-01', '2026-09-02');
        $this->assertSame('no_data', $noData->status);

        Carbon::setTestNow('2026-09-02 00:00:00');
        $this->calculate($service, '2026-09-01', '2026-09-02');
        $this->calculate($service, '2026-09-01', '2026-09-02');
        $this->assertSame(1, $service->slaMetrics()->count());
    }

    private function service(array $attributes = []): MonitoredService
    {
        return MonitoredService::factory()->create(array_merge(['sla_enabled' => true, 'sla_target_percent' => '99.90', 'created_at' => '2026-09-01 00:00:00'], $attributes));
    }

    private function incident(MonitoredService $service, string $start, ?string $end = null): void
    {
        ServiceIncident::query()->create(['monitored_service_id' => $service->id, 'started_at' => $start, 'confirmed_at' => $start, 'ended_at' => $end, 'status' => $end ? 'closed' : 'open']);
    }

    private function calculate(MonitoredService $service, string $from, string $to)
    {
        return app(SlaCalculator::class)->calculate($service, Carbon::parse($from), Carbon::parse($to));
    }
}
