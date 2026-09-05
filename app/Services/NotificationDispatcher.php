<?php

namespace App\Services;

use App\Jobs\DeliverNotificationJob;
use App\Models\MonitoredService;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\SlaMetric;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NotificationDispatcher
{
    public function incident(ServiceIncident $incident, string $event): void
    {
        $this->create($event, $incident->monitoredService, $incident, "incident:{$incident->id}:{$event}");
    }

    public function ssl(ServiceCheck $check, int $threshold): void
    {
        $identity = sha1(implode('|', [(string) $check->monitored_service_id, (string) data_get($check->metadata, 'expires_at'), (string) data_get($check->metadata, 'subject')]));
        $this->create('ssl_expiring', $check->monitoredService, null, "ssl:{$identity}:{$threshold}", ['check_id' => $check->id, 'threshold' => $threshold, 'expires_at' => data_get($check->metadata, 'expires_at'), 'days_remaining' => data_get($check->metadata, 'days_remaining')]);
    }

    public function health(string $component, string $event, string $cycle): void
    {
        $this->create("health_{$event}", null, null, "health:{$component}:{$cycle}:{$event}", ['component' => $component]);
    }

    public function sla(SlaMetric $metric): void
    {
        if (! in_array($metric->status, ['at_risk', 'breached'], true)) {
            return;
        }

        $period = $metric->period_start->format('Y-m');
        $this->create('sla_'.$metric->status, $metric->monitoredService, null, "sla:{$metric->monitored_service_id}:{$period}:{$metric->status}", [
            'target' => $metric->target_percent, 'actual' => $metric->availability_percent,
            'budget_used' => $metric->error_budget_consumed_percent, 'period' => $period,
            'downtime_seconds' => $metric->unplanned_downtime_seconds, 'allowed_seconds' => $metric->allowed_downtime_seconds,
        ]);
    }

    public function test(string $channel): NotificationDelivery
    {
        $setting = NotificationSetting::current();
        if ($channel === 'telegram' && (! $setting->telegram_enabled || blank($setting->telegram_bot_token) || blank($setting->telegram_chat_id))) {
            throw ValidationException::withMessages(['data.telegram_bot_token' => __('monitoring.notifications.invalid_telegram')]);
        }
        if ($channel === 'email' && (! $setting->email_enabled || ! $this->hasValidRecipients($setting->email_recipients ?? []))) {
            throw ValidationException::withMessages(['data.email_recipients' => __('monitoring.notifications.invalid_email')]);
        }

        $delivery = NotificationDelivery::query()->create([
            'event_type' => "{$channel}_test", 'channel' => $channel, 'status' => 'pending',
            'deduplication_key' => "test:{$channel}:".Str::uuid(), 'context' => ['application' => config('app.name'), 'environment' => config('app.env'), 'server_time' => now()->toIso8601String()],
        ]);
        DeliverNotificationJob::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }

    /** @param array<int, string> $recipients */
    private function hasValidRecipients(array $recipients): bool
    {
        return collect($recipients)->contains(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
    }

    /** @param array<string,mixed> $context */
    private function create(string $event, ?MonitoredService $service, ?ServiceIncident $incident, string $baseKey, array $context = []): void
    {
        if ($service !== null && ! $service->notifications_enabled) {
            return;
        }
        $setting = NotificationSetting::current();
        if ($event === 'ssl_expiring' && ! $setting->ssl_expiry_notifications_enabled) {
            return;
        }
        $channels = [];
        if ($setting->telegram_enabled && $setting->telegram_bot_token && $setting->telegram_chat_id) {
            $channels[] = 'telegram';
        }
        if ($setting->email_enabled && filled($setting->email_recipients)) {
            $channels[] = 'email';
        }
        foreach ($channels as $channel) {
            try {
                $delivery = NotificationDelivery::query()->create(['event_type' => $event, 'channel' => $channel, 'monitored_service_id' => $service?->id, 'service_incident_id' => $incident?->id, 'status' => 'pending', 'deduplication_key' => "{$baseKey}:{$channel}", 'context' => $context]);
                DeliverNotificationJob::dispatch($delivery->id)->afterCommit();
            } catch (QueryException $exception) { /* Unique key makes repeated events safe. */
            }
        }
    }
}
