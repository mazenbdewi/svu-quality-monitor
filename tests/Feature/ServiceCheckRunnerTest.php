<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Services\ServiceCheckRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceCheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_successful_service_check(): void
    {
        Http::fake([
            'https://example.test' => Http::response('Welcome to SVU', 200),
        ]);

        $service = MonitoredService::factory()->create([
            'url' => 'https://example.test',
            'expected_status_code' => 200,
            'expected_keyword' => 'SVU',
            'warning_response_ms' => 1500,
        ]);

        $check = app(ServiceCheckRunner::class)->run($service);

        $this->assertTrue($check->is_success);
        $this->assertFalse($check->is_slow);
        $this->assertSame(200, $check->status_code);
        $this->assertTrue($check->expected_keyword_found);
        $this->assertNull($check->error_type);
        $this->assertDatabaseHas('service_checks', [
            'id' => $check->id,
            'monitored_service_id' => $service->id,
            'is_success' => true,
            'source' => 'manual',
        ]);
    }

    public function test_it_fails_when_expected_keyword_is_missing(): void
    {
        Http::fake([
            'https://example.test' => Http::response('Different page', 200),
        ]);

        $service = MonitoredService::factory()->create([
            'url' => 'https://example.test',
            'expected_status_code' => 200,
            'expected_keyword' => 'SVU',
            'warning_response_ms' => 1500,
        ]);

        $check = app(ServiceCheckRunner::class)->run($service);

        $this->assertFalse($check->is_success);
        $this->assertSame('keyword_missing', $check->error_type);
        $this->assertFalse($check->expected_keyword_found);
    }

    public function test_it_stores_exception_failures(): void
    {
        Http::fake([
            'https://example.test' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $service = MonitoredService::factory()->create([
            'url' => 'https://example.test',
            'expected_keyword' => 'SVU',
        ]);

        $check = app(ServiceCheckRunner::class)->run($service);

        $this->assertFalse($check->is_success);
        $this->assertNull($check->status_code);
        $this->assertSame('timeout', $check->error_type);
        $this->assertFalse($check->expected_keyword_found);
        $this->assertSame('The request timed out.', $check->error_message);
    }

    public function test_it_evaluates_incidents_after_checks_are_stored(): void
    {
        Http::fake([
            'https://example.test' => Http::response('Server error', 500),
        ]);

        $service = MonitoredService::factory()->create([
            'url' => 'https://example.test',
            'expected_status_code' => 200,
            'expected_keyword' => null,
        ]);

        app(ServiceCheckRunner::class)->run($service);
        $this->assertDatabaseCount('service_incidents', 0);

        app(ServiceCheckRunner::class)->run($service);

        $this->assertDatabaseHas('service_incidents', [
            'monitored_service_id' => $service->id,
            'status' => 'open',
            'incident_type' => 'http_status_mismatch',
            'severity' => 'high',
        ]);
    }
}
