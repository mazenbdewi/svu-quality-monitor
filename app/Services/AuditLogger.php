<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    private const SENSITIVE = ['password', 'remember_token', 'telegram_bot_token', 'check_config', 'headers', 'authorization', 'api_key', 'token', 'cookie'];

    public function log(string $event, ?Model $model, string $description, array $before = [], array $after = [], array $context = []): void
    {
        AuditLog::query()->create(['actor_id' => auth()->id(), 'event' => $event, 'auditable_type' => $model ? $model::class : null, 'auditable_id' => $model?->getKey(), 'description' => $description, 'before' => $this->sanitize($before), 'after' => $this->sanitize($after), 'context' => $this->sanitize($context), 'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);
    }

    public function sanitize(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            $safe[$key] = in_array(strtolower((string) $key), self::SENSITIVE, true) ? __('monitoring.audit.sensitive_changed') : (is_array($value) ? $this->sanitize($value) : $value);
        }

        return $safe;
    }
}
