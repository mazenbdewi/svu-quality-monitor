<?php

namespace Tests\Feature;

use App\Models\ControlChart;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Services\ResearchInterpretationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchInterpretationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_level_returns_expected_levels(): void
    {
        $service = app(ResearchInterpretationService::class);

        $this->assertSame('no_data', $service->availabilityLevel(null));
        $this->assertSame('excellent', $service->availabilityLevel(99.0));
        $this->assertSame('acceptable', $service->availabilityLevel(95.0));
        $this->assertSame('needs_attention', $service->availabilityLevel(90.0));
        $this->assertSame('critical', $service->availabilityLevel(89.99));
    }

    public function test_legacy_chart_is_not_certified_stable_without_signals(): void
    {
        $chart = new ControlChart([
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'points_count' => 5,
            'out_of_control_count' => 0,
        ]);

        $finding = app(ResearchInterpretationService::class)->controlChartFinding($chart);

        $this->assertSame('legacy', $finding['level']);
        $this->assertSame('gray', $finding['color']);
    }

    public function test_control_chart_finding_returns_needs_investigation_for_repeated_signals(): void
    {
        $chart = new ControlChart([
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'points_count' => 8,
            'out_of_control_count' => 3,
        ]);

        $finding = app(ResearchInterpretationService::class)->controlChartFinding($chart);

        $this->assertSame('legacy', $finding['level']);
        $this->assertSame('warning', $finding['color']);
    }

    public function test_service_status_finding_handles_unknown_healthy_slow_and_down(): void
    {
        $interpretation = app(ResearchInterpretationService::class);

        $unknownService = MonitoredService::factory()->create();
        $this->assertSame('no_data', $interpretation->serviceStatusFinding($unknownService)['level']);

        $healthyService = MonitoredService::factory()->create();
        $this->createCheck($healthyService, true, false);
        $this->assertSame('stable', $interpretation->serviceStatusFinding($healthyService->fresh(['latestServiceCheck', 'openIncident']))['level']);

        $slowService = MonitoredService::factory()->create();
        $this->createCheck($slowService, true, true);
        $this->assertSame('warning', $interpretation->serviceStatusFinding($slowService->fresh(['latestServiceCheck', 'openIncident']))['level']);

        $downService = MonitoredService::factory()->create();
        $this->createCheck($downService, false, false, 'timeout');
        $this->assertSame('critical', $interpretation->serviceStatusFinding($downService->fresh(['latestServiceCheck', 'openIncident']))['level']);
    }

    public function test_comprehensive_report_findings_returns_array(): void
    {
        $service = MonitoredService::factory()->create();
        $this->createCheck($service, false, true, 'server_error');

        $findings = app(ResearchInterpretationService::class)->comprehensiveReportFindings();

        $this->assertIsArray($findings);
        $this->assertNotEmpty($findings);
        $this->assertArrayHasKey('title', $findings[0]);
        $this->assertArrayHasKey('recommendation', $findings[0]);
    }

    private function createCheck(MonitoredService $service, bool $isSuccess, bool $isSlow, ?string $errorType = null): void
    {
        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'checked_at' => now(),
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $isSlow ? 2500 : 300,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
            'error_type' => $errorType,
            'error_message' => $errorType ? 'Stored check error' : null,
            'expected_keyword_found' => $isSuccess,
        ]);
    }
}
