<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $event, ?Model $model, string $description, array $before = [], array $after = [], array $context = [], ?User $actor = null): void
    {
        AuditLog::query()->create(['actor_id' => $actor?->id ?? auth()->id(), 'event' => $event, 'auditable_type' => $model ? $model::class : null, 'auditable_id' => $model?->getKey(), 'description' => $description, 'before' => $this->sanitize($before), 'after' => $this->sanitize($after), 'context' => $this->sanitize($context), 'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent()]);
    }

    public function sanitize(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            $marker = ($key === 'password_changed' && is_bool($value))
                || ($key === 'telegram_token_action' && in_array($value, ['configured', 'replaced', 'removed'], true));
            $sensitive = preg_match('/password|passwd|token|secret|credential|authorization|cookie|apikey|checkconfig|headers|requestbody|apibody|privatekey/', $normalized)
                || in_array($normalized, ['body', 'payload'], true);
            $safe[$key] = $sensitive && ! $marker
                ? __('monitoring.audit.sensitive_changed')
                : (is_array($value) ? $this->sanitize($value) : (is_scalar($value) || $value === null ? $value : '[unsupported]'));
        }

        return $safe;
    }
}
