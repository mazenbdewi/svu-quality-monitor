<?php

namespace App\Monitoring;

use Carbon\Carbon;

class CheckResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $isSuccess,
        public readonly Carbon $checkedAt,
        public readonly ?int $statusCode = null,
        public readonly ?int $responseTimeMs = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
        public readonly ?bool $expectedKeywordFound = null,
        public readonly ?string $performanceStatus = null,
        public readonly array $metadata = [],
    ) {}
}
