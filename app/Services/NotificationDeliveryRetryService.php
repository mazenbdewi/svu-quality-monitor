<?php

namespace App\Services;

use App\Jobs\DeliverNotificationJob;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NotificationDeliveryRetryService
{
    public function retry(NotificationDelivery $delivery, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($delivery, $actor): bool {
            $locked = NotificationDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->status === 'sent') {
                return false;
            }
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('notifications.manage');
            }
            $locked->update(['status' => 'pending', 'safe_error_message' => null, 'error_category' => null]);
            DeliverNotificationJob::dispatch($locked->id)->afterCommit();

            if ($actor !== null) {
                app(AuditLogger::class)->log('notification.retry_requested', $locked, __('administration.audit.events')['notification.retry_requested'], context: ['channel' => $locked->channel, 'event_type' => $locked->event_type], actor: $actor);
            }

            return true;
        });
    }
}
