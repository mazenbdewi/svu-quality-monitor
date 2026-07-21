<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ServiceCheckRunner
{
    public function run(MonitoredService $service): ServiceCheck
    {
        $startedAt = now();
        $timerStartedAt = hrtime(true);
        $expectedKeyword = filled($service->expected_keyword) ? (string) $service->expected_keyword : null;

        try {
            $response = Http::timeout(10)->get($service->url);

            $responseTimeMs = $this->elapsedMilliseconds($timerStartedAt);
            $statusCode = $response->status();
            $expectedKeywordFound = $expectedKeyword === null
                ? null
                : Str::contains($response->body(), $expectedKeyword);

            $isStatusExpected = $statusCode === (int) $service->expected_status_code;
            $isSuccess = $isStatusExpected && ($expectedKeywordFound !== false);
            $isSlow = $responseTimeMs > (int) $service->warning_response_ms;

            $check = $service->serviceChecks()->create([
                'checked_at' => $startedAt,
                'status_code' => $statusCode,
                'response_time_ms' => $responseTimeMs,
                'is_success' => $isSuccess,
                'is_slow' => $isSlow,
                'error_type' => $isSuccess ? null : $this->failureType($isStatusExpected, $expectedKeywordFound),
                'error_message' => $isSuccess ? null : $this->failureMessage($service, $statusCode, $expectedKeywordFound),
                'expected_keyword_found' => $expectedKeywordFound,
            ]);

            app(IncidentDetector::class)->evaluate($service);

            return $check;
        } catch (Throwable $exception) {
            $responseTimeMs = $this->elapsedMilliseconds($timerStartedAt);

            $check = $service->serviceChecks()->create([
                'checked_at' => $startedAt,
                'status_code' => null,
                'response_time_ms' => $responseTimeMs,
                'is_success' => false,
                'is_slow' => false,
                'error_type' => $this->exceptionType($exception),
                'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                'expected_keyword_found' => $expectedKeyword === null ? null : false,
            ]);

            app(IncidentDetector::class)->evaluate($service);

            return $check;
        }
    }

    private function elapsedMilliseconds(int $timerStartedAt): int
    {
        return (int) round((hrtime(true) - $timerStartedAt) / 1_000_000);
    }

    private function exceptionType(Throwable $exception): string
    {
        $message = Str::lower($exception->getMessage());

        if (Str::contains($message, ['timed out', 'timeout', 'curl error 28'])) {
            return 'timeout';
        }

        if ($exception instanceof ConnectionException) {
            return 'connection_error';
        }

        if ($exception instanceof RequestException) {
            return 'request_error';
        }

        return 'unknown';
    }

    private function failureType(bool $isStatusExpected, ?bool $expectedKeywordFound): ?string
    {
        if (! $isStatusExpected) {
            return 'unexpected_status_code';
        }

        if ($expectedKeywordFound === false) {
            return 'keyword_missing';
        }

        return null;
    }

    private function failureMessage(MonitoredService $service, int $statusCode, ?bool $expectedKeywordFound): ?string
    {
        if ($statusCode !== (int) $service->expected_status_code) {
            return "Expected status code {$service->expected_status_code}, got {$statusCode}.";
        }

        if ($expectedKeywordFound === false) {
            return 'Expected keyword was not found in the response body.';
        }

        return null;
    }
}
