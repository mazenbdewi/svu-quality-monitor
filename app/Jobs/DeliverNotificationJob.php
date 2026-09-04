<?php

namespace App\Jobs;

use App\Mail\SystemAlertMail;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()->with(['monitoredService', 'serviceIncident'])->find($this->deliveryId);
        if ($delivery === null || $delivery->status === 'sent') {
            return;
        }
        $attemptNumber = max((int) $delivery->attempt_number + 1, $this->attempts());
        $delivery->update(['attempted_at' => now(), 'attempt_number' => $attemptNumber, 'status' => 'pending', 'error_category' => null, 'safe_error_message' => null]);
        try {
            $this->send($delivery);
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            $safe = $this->safeError($exception);
            $delivery->update(['status' => $this->attempts() >= $this->tries ? 'failed' : 'pending', 'error_category' => $this->errorCategory($exception), 'safe_error_message' => $safe]);
            throw $exception;
        }
    }

    private function send(NotificationDelivery $delivery): void
    {
        $setting = NotificationSetting::current();
        [$title, $lines] = $this->message($delivery);
        if ($delivery->channel === 'telegram') {
            if (! $setting->telegram_enabled || ! $setting->telegram_bot_token || ! $setting->telegram_chat_id) {
                throw new \RuntimeException('Telegram notification is not configured.');
            }
            Http::timeout(10)->post('https://api.telegram.org/bot'.$setting->telegram_bot_token.'/sendMessage', ['chat_id' => $setting->telegram_chat_id, 'text' => $title."\n".implode("\n", $lines)])->throw();

            return;
        }
        if ($delivery->channel === 'email') {
            $recipients = array_filter($setting->email_recipients ?? []);
            if (! $setting->email_enabled || $recipients === []) {
                throw new \RuntimeException('Email notification is not configured.');
            }
            Mail::to($recipients)->send(new SystemAlertMail($title, $lines));

            return;
        }
        throw new \RuntimeException('Unsupported notification channel.');
    }

    /** @return array{0:string,1:list<string>} */
    private function message(NotificationDelivery $delivery): array
    {
        $service = $delivery->monitoredService;
        $incident = $delivery->serviceIncident;
        if ($delivery->event_type === 'incident_confirmed') {
            return ['🔴 Service Down', ["Service: {$service?->name}", "Type: {$service?->check_type?->value}", 'Incident started: '.$incident?->started_at?->toDateTimeString(), 'Confirmed: '.$incident?->confirmed_at?->toDateTimeString(), 'Reason: '.($incident?->latest_failure_type ?? 'down')]];
        }
        if ($delivery->event_type === 'incident_resolved') {
            return ['🟢 Service Recovered', ["Service: {$service?->name}", 'Recovered: '.$incident?->resolved_at?->toDateTimeString(), 'Duration: '.($incident?->duration_minutes ?? 0).' minutes', 'Current status: Healthy']];
        }
        if ($delivery->event_type === 'ssl_expiring') {
            return ['🟠 SSL Certificate Expiring', ["Service: {$service?->name}", 'Expires: '.data_get($delivery->context, 'expires_at', 'Unknown'), 'Days remaining: '.data_get($delivery->context, 'days_remaining', 'Unknown'), 'Threshold: '.data_get($delivery->context, 'threshold').' days']];
        }
        if (in_array($delivery->event_type, ['telegram_test', 'email_test'], true)) {
            return [config('app.name').' — '.ucfirst($delivery->channel).' test', ['Notification test requested successfully.', 'Server time: '.data_get($delivery->context, 'server_time'), 'Environment: '.data_get($delivery->context, 'environment')]];
        }
        $component = ucfirst((string) data_get($delivery->context, 'component'));

        return [$delivery->event_type === 'health_resolved' ? '🟢 Background Service Recovered' : '🔴 Background Service Problem', ["Component: {$component}", 'Status: '.($delivery->event_type === 'health_resolved' ? 'Healthy' : 'Down')]];
    }

    private function errorCategory(Throwable $exception): string
    {
        return $exception instanceof RequestException ? 'transport' : 'delivery';
    }

    private function safeError(Throwable $exception): string
    {
        return $exception instanceof RequestException ? 'The remote notification service rejected or could not receive the request.' : 'The notification could not be delivered.';
    }
}
