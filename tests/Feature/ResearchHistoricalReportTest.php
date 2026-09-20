<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Reports\ComprehensivePdfReport;
use App\Reports\ExecutiveMonthlyPdfReport;
use App\Services\ExecutiveDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchHistoricalReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_report_does_not_use_future_checks_or_current_incident_status(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        $s = MonitoredService::factory()->create(['created_at' => '2026-08-01', 'operational_state' => 'healthy']);
        $s->serviceChecks()->create(['checked_at' => '2026-09-29', 'check_type' => 'http', 'is_success' => false, 'is_slow' => false]);
        $s->serviceChecks()->create(['checked_at' => '2026-10-04', 'check_type' => 'ssl', 'is_success' => true, 'is_slow' => false, 'metadata' => ['days_remaining' => 2]]);
        $s->serviceIncidents()->create(['started_at' => '2026-09-29 00:00:00', 'confirmed_at' => '2026-09-29 00:01:00', 'ended_at' => '2026-10-01', 'status' => 'closed', 'incident_type' => 'availability', 'severity' => 'critical']);
        $data = (new ExecutiveMonthlyPdfReport('2026-09-01'))->data();
        $this->assertSame(1, $data['dashboard']['counts']['open_incidents']);
        $this->assertSame(0, $data['dashboard']['ssl']['within_30']);
        $html = view('reports.executive-monthly-pdf', $data)->render();
        $this->assertStringContainsString('47:59:59', $html);
        $this->assertSame('healthy', $s->fresh()->operational_state);
    }

    public function test_current_open_incident_does_not_extend_into_future_month_end(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $s = MonitoredService::factory()->create();
        $s->serviceIncidents()->create(['started_at' => '2026-09-15 11:00:00', 'confirmed_at' => '2026-09-15 11:01:00', 'status' => 'open', 'incident_type' => 'availability', 'severity' => 'critical']);
        $this->assertSame(3600, app(ExecutiveDashboardService::class)->snapshot()['incidents']['downtime_seconds']);
    }

    public function test_comprehensive_report_retains_every_requested_daily_reliability_row(): void
    {
        $s = MonitoredService::factory()->create();
        foreach ([1, 2, 3] as $day) {
            ReliabilityMetric::create(['monitored_service_id' => $s->id, 'period_type' => 'daily', 'period_start' => "2026-09-0$day", 'period_end' => '2026-09-0'.($day + 1), 'availability_percent' => 99]);
        }
        $report = new ComprehensivePdfReport(['date_from' => '2026-09-01', 'date_to' => '2026-09-02', 'period_type' => 'daily']);
        $this->assertCount(2, $report->reliabilityMetricsSummary());
    }

    public function test_comprehensive_incident_state_and_duration_are_cutoff_bounded(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        $s = MonitoredService::factory()->create();
        $s->serviceIncidents()->create(['started_at' => '2026-09-30 23:00:00', 'confirmed_at' => '2026-09-30 23:01:00', 'ended_at' => '2026-10-03', 'status' => 'closed', 'incident_type' => 'availability', 'severity' => 'critical']);
        $report = new ComprehensivePdfReport(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);
        $this->assertSame(1, $report->summary()['open_incidents']);
        $this->assertSame(0, $report->summary()['closed_incidents']);
        $this->assertEquals(60, $report->incidentsSummary()->first()->average_duration_minutes);
    }
}
