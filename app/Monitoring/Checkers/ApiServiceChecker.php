<?php

namespace App\Monitoring\Checkers;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class ApiServiceChecker extends HttpServiceChecker
{
    public function type(): MonitoringCheckType
    {
        return MonitoringCheckType::Api;
    }

    public function check(MonitoredService $service): CheckResult
    {
        $startedAt = now();
        $timer = hrtime(true);
        $config = $service->check_config ?? [];
        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];

        try {
            $request = Http::timeout($service->timeoutSeconds())->withHeaders($headers);
            $response = $method === 'POST'
                ? $request->post($service->url, $config['body'] ?? [])
                : $request->get($service->url);
            $json = $response->json();
            $pathConfigured = filled($config['json_path'] ?? null);
            if ($pathConfigured && ! is_array($json)) {
                return new CheckResult(false, $startedAt, $response->status(), $this->elapsed($timer), 'invalid_json', 'The API response was not valid JSON.', metadata: ['actual_status' => $response->status()]);
            }
            $jsonAssertion = $this->jsonAssertion($json, $config);
            $success = $response->status() === (int) $service->expected_status_code && $jsonAssertion !== false;

            return new CheckResult($success, $startedAt, $response->status(), $this->elapsed($timer), $success ? null : ($jsonAssertion === false ? 'json_expectation_failed' : 'http_status_mismatch'), $success ? null : 'The API response did not meet the configured expectation.', metadata: ['actual_status' => $response->status(), 'json_assertion' => $jsonAssertion]);
        } catch (Throwable $exception) {
            return new CheckResult(false, $startedAt, responseTimeMs: $this->elapsed($timer), errorType: $this->errorType($exception), errorMessage: $this->safeMessage($exception));
        }
    }

    private function jsonAssertion(mixed $json, array $config): ?bool
    {
        $path = $config['json_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (! is_array($json)) {
            return false;
        }
        $value = data_get($json, $path, new \stdClass);
        if ($value instanceof \stdClass) {
            return false;
        }

        return array_key_exists('json_expected_value', $config) ? (string) $value === (string) $config['json_expected_value'] : true;
    }
}
