<?php

namespace App\Monitoring\Checkers;

use App\Enums\MonitoringCheckType;
use App\Models\MonitoredService;
use App\Monitoring\CheckResult;
use App\Monitoring\Contracts\ServiceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class HttpServiceChecker implements ServiceChecker
{
    public function type(): MonitoringCheckType
    {
        return MonitoringCheckType::Http;
    }

    public function check(MonitoredService $service): CheckResult
    {
        $startedAt = now();
        $timer = hrtime(true);
        $expectedKeyword = filled($service->expected_keyword) ? (string) $service->expected_keyword : null;

        try {
            $response = Http::timeout($service->timeoutSeconds())->get($service->url);
            $keywordFound = $expectedKeyword === null ? null : Str::contains($response->body(), $expectedKeyword);
            $success = $response->status() === (int) $service->expected_status_code && $keywordFound !== false;

            return new CheckResult($success, $startedAt, $response->status(), $this->elapsed($timer), $success ? null : ($keywordFound === false ? 'keyword_missing' : 'http_status_mismatch'), $success ? null : 'The HTTP response did not meet the configured expectation.', $keywordFound, metadata: ['actual_status' => $response->status()]);
        } catch (Throwable $exception) {
            return new CheckResult(false, $startedAt, responseTimeMs: $this->elapsed($timer), errorType: $this->errorType($exception), errorMessage: $this->safeMessage($exception), expectedKeywordFound: $expectedKeyword === null ? null : false);
        }
    }

    protected function elapsed(int $timer): int
    {
        return (int) round((hrtime(true) - $timer) / 1_000_000);
    }

    protected function errorType(Throwable $exception): string
    {
        $message = Str::lower($exception->getMessage());
        if (Str::contains($message, ['timed out', 'timeout', 'curl error 28'])) {
            return 'timeout';
        }
        if (Str::contains($message, ['could not resolve', 'name resolution'])) {
            return 'dns_failure';
        }
        if (Str::contains($message, ['connection refused'])) {
            return 'connection_refused';
        }

        return $exception instanceof ConnectionException ? 'connection_error' : ($exception instanceof RequestException ? 'request_error' : 'unknown');
    }

    protected function safeMessage(Throwable $exception): string
    {
        return $this->errorType($exception) === 'timeout' ? 'The request timed out.' : 'The HTTP request could not be completed.';
    }
}
