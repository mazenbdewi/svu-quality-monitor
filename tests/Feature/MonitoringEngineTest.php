<?php

namespace Tests\Feature;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\Checkers\DnsServiceChecker;
use App\Monitoring\Checkers\SslServiceChecker;
use App\Monitoring\Checkers\TcpServiceChecker;
use App\Monitoring\CheckResult;
use App\Monitoring\Contracts\DnsResolver;
use App\Monitoring\Contracts\SocketProbe;
use App\Monitoring\Contracts\SslCertificateProbe;
use App\Services\ControlChartCalculator;
use App\Services\ReliabilityMetricCalculator;
use App\Services\ServiceCheckRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_time_is_classified_as_healthy_warning_and_critical_without_an_outage(): void
    {
        $service = MonitoredService::factory()->create(['warning_response_ms' => 100, 'critical_response_ms' => 200]);
        $runner = app(ServiceCheckRunner::class);
        $healthy = $runner->persist($service, new CheckResult(true, now(), responseTimeMs: 99));
        $warning = $runner->persist($service, new CheckResult(true, now(), responseTimeMs: 100));
        $critical = $runner->persist($service, new CheckResult(true, now(), responseTimeMs: 200));

        $this->assertSame('healthy', $healthy->performance_status);
        $this->assertSame('warning', $warning->performance_status);
        $this->assertTrue($warning->is_slow);
        $this->assertSame('critical', $critical->performance_status);
        $this->assertTrue($critical->is_slow);
        $this->assertDatabaseCount('service_incidents', 0);

        $metric = app(ReliabilityMetricCalculator::class)->calculateForService($service, now()->startOfDay(), now()->endOfDay());
        $this->assertNull($metric->availability_percent); // Manual-only observations cannot establish research availability.
        $this->assertSame('no_data', $metric->measurement_context['status']);
    }

    public function test_api_checker_supports_get_post_json_expectations_and_does_not_store_headers(): void
    {
        Http::fake([
            'https://api.test/get' => Http::response(['data' => ['status' => 'ok']], 200),
            'https://api.test/post' => Http::response(['created' => true], 201),
        ]);
        $get = MonitoredService::factory()->create([
            'url' => 'https://api.test/get', 'check_type' => MonitoringCheckType::Api,
            'check_config' => ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer secret-token'], 'json_path' => 'data.status', 'json_expected_value' => 'ok'],
        ]);
        $post = MonitoredService::factory()->create([
            'url' => 'https://api.test/post', 'check_type' => MonitoringCheckType::Api, 'expected_status_code' => 201,
            'check_config' => ['method' => 'POST', 'body' => ['name' => 'SVU']],
        ]);

        $first = app(ServiceCheckRunner::class)->run($get);
        $second = app(ServiceCheckRunner::class)->run($post);

        $this->assertTrue($first->is_success);
        $this->assertTrue($second->is_success);
        $this->assertSame(true, $first->metadata['json_assertion']);
        $this->assertArrayNotHasKey('headers', $first->metadata);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.test/post' && $request['name'] === 'SVU');
    }

    public function test_api_json_expectation_failure_is_functional_failure(): void
    {
        Http::fake(['https://api.test/json' => Http::response(['ready' => false], 200)]);
        $service = MonitoredService::factory()->create([
            'url' => 'https://api.test/json', 'check_type' => MonitoringCheckType::Api,
            'check_config' => ['json_path' => 'ready', 'json_expected_value' => 'true'],
        ]);

        $check = app(ServiceCheckRunner::class)->run($service);

        $this->assertFalse($check->is_success);
        $this->assertSame('json_expectation_failed', $check->error_type);
    }

    public function test_dns_checker_uses_injected_resolver_and_stores_limited_records(): void
    {
        $resolver = new class implements DnsResolver
        {
            public function resolve(string $hostname, string $recordType): array
            {
                return [['ip' => '192.0.2.10']];
            }
        };
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Dns, 'url' => 'example.test', 'check_config' => ['record_type' => 'A']]);
        $result = (new DnsServiceChecker($resolver))->check($service);

        $this->assertTrue($result->isSuccess);
        $this->assertSame(['192.0.2.10'], $result->metadata['records']);
    }

    public function test_dns_failure_is_machine_readable(): void
    {
        $resolver = new class implements DnsResolver
        {
            public function resolve(string $hostname, string $recordType): array
            {
                return [];
            }
        };
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Dns, 'url' => 'missing.test']);
        $result = (new DnsServiceChecker($resolver))->check($service);

        $this->assertFalse($result->isSuccess);
        $this->assertSame('dns_failure', $result->errorType);
    }

    public function test_ssl_checker_records_expiry_and_near_expiry_is_not_an_outage(): void
    {
        Carbon::setTestNow('2026-08-31 00:00:00');
        $probe = new class implements SslCertificateProbe
        {
            public function inspect(string $hostname, int $port, int $timeoutSeconds): array
            {
                return ['issuer' => ['CN' => 'Test CA'], 'subject' => ['CN' => 'example.test'], 'validFrom_time_t' => now()->subDay()->timestamp, 'validTo_time_t' => now()->addDays(5)->timestamp];
            }
        };
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Ssl, 'url' => 'example.test']);
        $check = app(ServiceCheckRunner::class)->persist($service, (new SslServiceChecker($probe))->check($service));

        $this->assertTrue($check->is_success);
        $this->assertSame('warning', $check->performance_status);
        $this->assertSame(5, $check->metadata['days_remaining']);
        $this->assertDatabaseCount('service_incidents', 0);
        Carbon::setTestNow();
    }

    public function test_ssl_expiry_is_a_functional_failure(): void
    {
        $probe = new class implements SslCertificateProbe
        {
            public function inspect(string $hostname, int $port, int $timeoutSeconds): array
            {
                return ['validTo_time_t' => now()->subDay()->timestamp];
            }
        };
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Ssl, 'url' => 'example.test']);
        $result = (new SslServiceChecker($probe))->check($service);
        $this->assertFalse($result->isSuccess);
        $this->assertSame('certificate_expired', $result->errorType);
    }

    public function test_ssl_verification_failure_is_classified_without_a_real_certificate(): void
    {
        $probe = new class implements SslCertificateProbe
        {
            public function inspect(string $hostname, int $port, int $timeoutSeconds): array
            {
                throw new \RuntimeException('certificate verify failed');
            }
        };
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Ssl, 'url' => 'example.test']);

        $result = (new SslServiceChecker($probe))->check($service);

        $this->assertFalse($result->isSuccess);
        $this->assertSame('tls_failure', $result->errorType);
    }

    public function test_tcp_checker_reports_success_refusal_and_timeout_without_network_access(): void
    {
        $service = MonitoredService::factory()->create(['check_type' => MonitoringCheckType::Tcp, 'url' => '127.0.0.1', 'check_config' => ['port' => 3306]]);
        $success = new class implements SocketProbe
        {
            public function connect(string $hostname, int $port, int $timeoutSeconds): array
            {
                return ['success' => true, 'error_type' => null, 'error_message' => null];
            }
        };
        $refused = new class implements SocketProbe
        {
            public function connect(string $hostname, int $port, int $timeoutSeconds): array
            {
                return ['success' => false, 'error_type' => 'connection_refused', 'error_message' => 'TCP connection could not be established.'];
            }
        };
        $timeout = new class implements SocketProbe
        {
            public function connect(string $hostname, int $port, int $timeoutSeconds): array
            {
                return ['success' => false, 'error_type' => 'timeout', 'error_message' => 'TCP connection could not be established.'];
            }
        };
        $this->assertTrue((new TcpServiceChecker($success))->check($service)->isSuccess);
        $failed = (new TcpServiceChecker($refused))->check($service);
        $this->assertFalse($failed->isSuccess);
        $this->assertSame('connection_refused', $failed->errorType);
        $this->assertSame('timeout', (new TcpServiceChecker($timeout))->check($service)->errorType);
    }

    public function test_check_config_is_encrypted_and_legacy_services_default_to_http(): void
    {
        $service = MonitoredService::factory()->create(['check_config' => ['headers' => ['Authorization' => 'Bearer top-secret']]]);
        $raw = DB::table('monitored_services')->whereKey($service->id)->value('check_config');

        $this->assertSame(MonitoringCheckType::Http, $service->check_type);
        $this->assertStringNotContainsString('top-secret', (string) $raw);
        $this->assertSame('Bearer top-secret', $service->fresh()->check_config['headers']['Authorization']);
    }

    public function test_control_charts_ignore_non_http_and_non_api_response_metrics(): void
    {
        $service = MonitoredService::factory()->create();
        $service->serviceChecks()->create(['source' => 'automatic', 'checked_at' => now()->subMinute(), 'check_type' => 'http', 'response_time_ms' => 100, 'is_success' => true, 'is_slow' => false]);
        $service->serviceChecks()->create(['source' => 'automatic', 'checked_at' => now()->subSeconds(30), 'check_type' => 'tcp', 'response_time_ms' => 9000, 'is_success' => true, 'is_slow' => false]);

        $chart = app(ControlChartCalculator::class)->calculate($service, 'i_chart', now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $chart->points_count);
        $this->assertSame(100.0, (float) $chart->center_line);
    }
}
