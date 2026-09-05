<?php

namespace Tests\Feature;

use App\Models\InstitutionSetting;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use App\Reports\ExecutiveMonthlyPdfReport;
use App\Services\ExecutiveDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveDashboardAndReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_uses_weighted_availability_and_sla_counts(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();
        $healthy = MonitoredService::factory()->create(['sla_enabled' => true, 'sla_target_percent' => 99.9]);
        $breached = MonitoredService::factory()->create(['sla_enabled' => true, 'sla_target_percent' => 99.9]);
        $this->metric($healthy, $start, $end, 1000, 10, 'met', 10);
        $this->metric($breached, $start, $end, 100, 50, 'breached', 500);
        ServiceCheck::query()->create(['monitored_service_id' => $healthy->id, 'checked_at' => now(), 'check_type' => 'http', 'is_success' => true, 'is_slow' => false]);
        ServiceCheck::query()->create(['monitored_service_id' => $breached->id, 'checked_at' => now(), 'check_type' => 'http', 'is_success' => false, 'is_slow' => false]);

        $snapshot = app(ExecutiveDashboardService::class)->snapshot();

        $this->assertSame(1, $snapshot['counts']['healthy']);
        $this->assertSame(1, $snapshot['counts']['down']);
        $this->assertSame(1, $snapshot['sla']['met']);
        $this->assertSame(1, $snapshot['sla']['breached']);
        $this->assertEqualsWithDelta((1040 / 1100) * 100, $snapshot['sla']['weighted_availability'], 0.001);
    }

    public function test_executive_report_uses_requested_month_and_historical_snapshot_target(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $service = MonitoredService::factory()->create(['name' => 'Portal', 'sla_enabled' => true, 'sla_target_percent' => 99.0]);
        $start = Carbon::parse('2026-09-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $this->metric($service, $start, $end, 1000, 5, 'met', 50, 99.95);
        $service->update(['sla_target_percent' => 90.0]);
        InstitutionSetting::current()->update(['institution_name' => 'SVU', 'institution_logo' => null]);

        $report = new ExecutiveMonthlyPdfReport('2026-09-01');
        $data = $report->data();
        $html = view('reports.executive-monthly-pdf', $data)->render();

        $this->assertTrue($report->hasData());
        $this->assertSame('2026-09-01', $data['periodStart']->toDateString());
        $this->assertSame('SVU', $data['institution']->institution_name);
        $this->assertStringContainsString('SVU', $html);
        $this->assertStringContainsString('99.50%', $html);
    }

    public function test_open_incident_is_clipped_at_end_of_historical_report_period(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $service = MonitoredService::factory()->create(['sla_enabled' => true]);
        ServiceIncident::query()->create(['monitored_service_id' => $service->id, 'started_at' => '2026-09-30 23:00:00', 'confirmed_at' => '2026-09-30 23:00:00', 'status' => 'open', 'incident_type' => 'down', 'severity' => 'high']);
        $snapshot = app(ExecutiveDashboardService::class)->snapshot(Carbon::parse('2026-09-30 23:59:59'));

        $this->assertSame(3599, $snapshot['incidents']['downtime_seconds']);
    }

    private function metric(MonitoredService $service, Carbon $start, Carbon $end, int $eligible, int $downtime, string $status, float $budget, ?float $availability = null): void
    {
        SlaMetric::query()->create(['monitored_service_id' => $service->id, 'period_start' => $start, 'period_end' => $end, 'target_percent' => 99.9, 'eligible_observation_seconds' => $eligible, 'planned_maintenance_seconds' => 0, 'unplanned_downtime_seconds' => $downtime, 'availability_percent' => $availability ?? (($eligible - $downtime) / $eligible) * 100, 'allowed_downtime_seconds' => 1, 'error_budget_consumed_seconds' => $downtime, 'error_budget_remaining_seconds' => 0, 'error_budget_consumed_percent' => $budget, 'status' => $status, 'calculated_at' => now()]);
    }
}
