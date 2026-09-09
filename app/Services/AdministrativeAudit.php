<?php

namespace App\Services;

use App\Models\InstitutionSetting;
use App\Models\MaintenanceWindow;
use App\Models\MonitoredService;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AdministrativeAudit
{
    private const FIELDS = [
        MonitoredService::class => ['name', 'check_type', 'is_active', 'expected_status_code', 'check_interval_minutes', 'warning_response_ms', 'critical_response_ms', 'sla_enabled', 'sla_target_percent', 'notifications_enabled', 'failure_confirmation_count', 'recovery_confirmation_count'],
        MaintenanceWindow::class => ['name', 'starts_at', 'ends_at', 'applies_to_all_services'],
        User::class => ['name', 'email', 'is_active'],
        NotificationSetting::class => ['telegram_enabled', 'email_enabled', 'ssl_expiry_notifications_enabled'],
        InstitutionSetting::class => ['institution_name'],
    ];

    // Fingerprints are transient comparison data only, never audit payloads.
    public function snapshot(Model $model): array
    {
        $fields = $model->only(self::FIELDS[$model::class] ?? []);
        foreach ($fields as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $fields[$key] = $value->value;
            }
            if ($value instanceof \DateTimeInterface) {
                $fields[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        if ($model instanceof MaintenanceWindow) {
            $fields['service_ids'] = $model->monitoredServices()->orderBy('monitored_services.id')->pluck('monitored_services.id')->all();
        }
        if ($model instanceof User) {
            $fields['roles'] = $model->roles()->orderBy('name')->pluck('name')->all();
        }
        $secrets = match ($model::class) {
            MonitoredService::class => ['monitoring' => $model->check_config, 'other_configuration' => $model->only(['url', 'expected_keyword', 'notes', 'category'])],
            MaintenanceWindow::class => ['description' => $model->description],
            NotificationSetting::class => ['telegram' => $model->telegram_bot_token, 'recipients' => $model->email_recipients, 'chat' => $model->telegram_chat_id],
            User::class => ['password' => $model->password],
            InstitutionSetting::class => ['logo' => $model->institution_logo],
            default => [],
        };

        return ['fields' => $fields, 'fingerprints' => array_map(fn ($value) => $this->fingerprint($value), $secrets)];
    }

    private function fingerprint(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = array_filter(array_map(fn ($item) => $this->fingerprint($item), $value), fn ($item) => $item !== null);
            ksort($value);
        }

        return blank($value) ? null : hash('sha256', json_encode($value));
    }

    public function record(Model $model, array $before, string $operation = 'updated', ?User $actor = null): void
    {
        $after = $operation === 'deleted' ? ['fields' => [], 'fingerprints' => []] : $this->snapshot($model);
        $old = $before['fields'] ?? [];
        $new = $after['fields'];
        $changed = array_keys(array_filter($new, fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        $markers = [];
        foreach ($after['fingerprints'] as $key => $value) {
            if (($before['fingerprints'][$key] ?? null) !== $value) {
                $markers[$key] = true;
            }
        }
        if ($operation === 'updated' && $changed === [] && $markers === []) {
            return;
        }
        $prefix = match ($model::class) {
            MonitoredService::class => 'service', MaintenanceWindow::class => 'maintenance', User::class => 'user', NotificationSetting::class => 'notification', InstitutionSetting::class => 'institution',
        };
        $context = [];
        if ($model instanceof MonitoredService) {
            $context = ['sla_changed' => (bool) array_intersect($changed, ['sla_enabled', 'sla_target_percent']), 'sensitive_monitoring_configuration_changed' => isset($markers['monitoring']), 'other_configuration_changed' => isset($markers['other_configuration'])];
            if ($operation === 'updated') {
                $operation = match (true) {
                    $changed === ['is_active'] && $markers === [] => $model->is_active ? 'enabled' : 'disabled',
                    $changed !== [] && array_diff($changed, ['sla_enabled', 'sla_target_percent']) === [] && $markers === [] => 'sla_updated',
                    array_diff($changed, ['check_type']) === [] && isset($markers['monitoring']) => 'monitoring_config_updated',
                    default => 'updated',
                };
            }
        }
        if ($model instanceof NotificationSetting) {
            $operation = 'settings_updated';
            if (isset($markers['telegram'])) {
                $context['telegram_token_action'] = $after['fingerprints']['telegram'] === null ? 'removed' : (empty($before['fingerprints']['telegram']) ? 'configured' : 'replaced');
            }
            $context['recipient_list_changed'] = isset($markers['recipients']);
            $context['telegram_destination_changed'] = isset($markers['chat']);
        }
        if ($model instanceof InstitutionSetting) {
            $context['logo_changed'] = isset($markers['logo']);
        }
        if ($model instanceof MaintenanceWindow) {
            $context['description_changed'] = isset($markers['description']);
        }
        if ($model instanceof User && $operation === 'updated') {
            $groups = ['updated' => ['name', 'email'], 'role_changed' => ['roles'], ($model->is_active ? 'activated' : 'deactivated') => ['is_active']];
            foreach ($groups as $event => $fields) {
                $keys = array_intersect($fields, $changed);
                if ($keys !== []) {
                    $this->write('user.'.$event, $model, array_intersect_key($old, array_flip($keys)), array_intersect_key($new, array_flip($keys)), [], $actor);
                }
            }
            if (isset($markers['password'])) {
                $this->write('user.password_changed_by_admin', $model, [], [], ['password_changed' => true], $actor);
            }

            return;
        }
        $keys = array_flip($changed);
        $this->write($prefix.'.'.$operation, $model, $operation === 'deleted' ? $old : array_intersect_key($old, $keys), $operation === 'deleted' ? [] : array_intersect_key($new, $keys), $context, $actor);
    }

    public function delete(Model $record): bool
    {
        return DB::transaction(function () use ($record): bool {
            $before = $this->snapshot($record);
            if (! $record->delete()) {
                return false;
            }
            $this->record($record, $before, 'deleted');

            return true;
        });
    }

    private function write(string $event, Model $model, array $before, array $after, array $context, ?User $actor): void
    {
        $events = __('administration.audit.events');
        app(AuditLogger::class)->log($event, $model, $events[$event] ?? $event, $before, $after, $context, $actor);
    }
}
