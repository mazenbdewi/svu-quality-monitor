<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\AuditLog;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Monitoring\Checkers\ApiServiceChecker;
use App\Monitoring\Checkers\HttpServiceChecker;
use App\Monitoring\CheckResult;
use App\Monitoring\MeasurementLimits;
use App\Services\AdministrativeAudit;
use App\Services\ControlChartCalculator;
use App\Services\ServiceCheckRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchMeasurementProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_preserves_functional_success_and_does_not_turn_critical_performance_into_outage(): void
    {
        $service = MonitoredService::factory()->create(['warning_response_ms' => 100, 'critical_response_ms' => 200, 'failure_confirmation_count' => 1]);
        foreach ([[99, true, false, 'healthy'], [100, true, true, 'warning'], [200, true, true, 'critical']] as [$ms, $success, $slow, $status]) {
            $check = app(ServiceCheckRunner::class)->persist($service, new CheckResult($success, now(), 200, $ms), 'automatic');
            $this->assertTrue($check->is_success);
            $this->assertSame($slow, $check->is_slow);
            $this->assertSame($slow, $check->is_problematic);
            $this->assertSame($status, $check->performance_status);
            $this->assertDatabaseCount('service_incidents', 0);
        }
        $this->assertSame('healthy', $service->fresh()->operational_state);
        $check = app(ServiceCheckRunner::class)->persist($service, new CheckResult(false, now(), responseTimeMs: 1000, errorType: 'timeout'), 'automatic');
        $this->assertFalse($check->is_success);
        $this->assertFalse($check->is_slow);
        $this->assertTrue($check->is_problematic);
        $this->assertSame(1, $service->serviceIncidents()->count());
    }

    public function test_research_samples_share_provenance_but_distinguish_failures_from_completed_latency(): void
    {
        $service = MonitoredService::factory()->create();
        $base = ['checked_at' => now(), 'source' => 'automatic', 'check_type' => 'http', 'response_time_ms' => 100, 'is_success' => true, 'is_slow' => false, 'is_during_maintenance' => false];
        $http = $service->serviceChecks()->create($base);
        $api = $service->serviceChecks()->create(array_replace($base, ['check_type' => 'api', 'is_slow' => true, 'performance_status' => 'critical']));
        foreach ([['source' => 'manual'], ['is_during_maintenance' => true], ['check_type' => 'dns'], ['check_type' => 'ssl'], ['check_type' => 'tcp'], ['metadata' => ['is_synthetic' => true]], ['metadata' => ['is_diagnostic' => true]]] as $override) {
            $service->serviceChecks()->create(array_replace($base, $override));
        }
        $failure = $service->serviceChecks()->create(array_replace($base, ['is_success' => false, 'error_type' => 'timeout', 'response_time_ms' => 20000]));
        $missingTime = $service->serviceChecks()->create(array_replace($base, ['response_time_ms' => null]));
        $this->assertSame([$http->id, $api->id], ServiceCheck::query()->researchResponseTimes()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$http->id, $api->id, $failure->id, $missingTime->id], ServiceCheck::query()->researchChecks()->orderBy('id')->pluck('id')->all());
    }

    public function test_http_and_api_timestamp_is_attempt_start_even_when_completion_is_later(): void
    {
        foreach ([HttpServiceChecker::class, ApiServiceChecker::class] as $checker) {
            $this->travelTo(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
            Http::swap(new Factory);
            Http::fake(function () {
                $this->travel(3)->seconds();

                return Http::response('ok', 200);
            });
            $service = MonitoredService::factory()->create(['expected_keyword' => null]);
            $result = app($checker)->check($service);
            $this->assertSame('2026-09-01 10:00:00', $result->checkedAt->toDateTimeString());
            $this->assertSame('2026-09-01 10:00:03', now()->toDateTimeString());
        }
        $this->travelBack();
    }

    public function test_timeout_keeps_attempt_start_and_elapsed_diagnostic_value_without_becoming_latency_sample(): void
    {
        foreach (['http', 'api'] as $type) {
            $this->travelTo(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
            Http::swap(new Factory);
            Http::fake(function () {
                $this->travel(2)->seconds();
                throw new ConnectionException('Connection timed out');
            });
            $service = MonitoredService::factory()->create(['check_type' => $type]);
            $check = app(ServiceCheckRunner::class)->run($service, 'automatic');
            $this->assertSame('2026-09-01 10:00:00', $check->checked_at->toDateTimeString());
            $this->assertSame('timeout', $check->error_type);
            $this->assertNotNull($check->response_time_ms);
            $this->assertTrue($check->is_problematic);
            $this->assertTrue($service->serviceChecks()->researchChecks()->exists());
            $this->assertFalse($service->serviceChecks()->researchResponseTimes()->exists());
        }
        $this->travelBack();
    }

    public function test_existing_spc_counts_critical_success_as_problematic_without_changing_formulas(): void
    {
        $service = MonitoredService::factory()->create(['warning_response_ms' => 100, 'critical_response_ms' => 200]);
        $at = Carbon::parse('2026-09-01 10:00:00');
        app(ServiceCheckRunner::class)->persist($service, new CheckResult(true, $at, 200, 250), 'automatic');
        app(ServiceCheckRunner::class)->persist($service, new CheckResult(true, $at->copy()->addMinute(), 200, 50), 'automatic');
        $chart = app(ControlChartCalculator::class)->calculate($service, 'p_chart', $at->copy()->startOfDay(), $at->copy()->endOfDay());
        $this->assertSame('0.5000', $chart->center_line);
        $this->assertSame(1, $chart->points()->first()->research_context['problematic_count']);
        $this->assertSame(0, $chart->points()->first()->failed_count);
        $this->assertDatabaseCount('service_incidents', 0);
    }

    public function test_timeout_budget_is_bounded_for_existing_configurations_and_deployment(): void
    {
        $service = MonitoredService::factory()->make(['check_config' => ['timeout_seconds' => 60]]);
        $this->assertSame(20, $service->timeoutSeconds());
        $this->assertGreaterThan($service->timeoutSeconds(), (new CheckMonitoredServiceJob(1))->timeout);
        $this->assertSame(MeasurementLimits::JOB_TIMEOUT_SECONDS, (new CheckMonitoredServiceJob(1))->timeout);
        $compose = file_get_contents(base_path('compose.yaml'));
        preg_match('/--timeout=(\d+)/', $compose, $matches);
        $this->assertGreaterThanOrEqual(MeasurementLimits::JOB_TIMEOUT_SECONDS, (int) $matches[1]);
        $this->assertGreaterThan((int) $matches[1], config('queue.connections.database.retry_after'));
    }

    public function test_measurement_configuration_changes_are_audited_without_exposing_endpoint(): void
    {
        $service = MonitoredService::factory()->create();
        $audit = app(AdministrativeAudit::class);
        $before = $audit->snapshot($service);
        $service->update(['url' => 'https://example.test/new?secret=hidden', 'check_interval_minutes' => 7, 'warning_response_ms' => 123, 'critical_response_ms' => 456, 'check_type' => 'api']);
        $audit->record($service, $before);
        $log = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertTrue($log->context['endpoint_changed']);
        foreach (['check_interval_minutes', 'warning_response_ms', 'critical_response_ms', 'check_type'] as $field) {
            $this->assertArrayHasKey($field, $log->after);
        }
        $this->assertStringNotContainsString('secret=hidden', $log->toJson());
    }
}
