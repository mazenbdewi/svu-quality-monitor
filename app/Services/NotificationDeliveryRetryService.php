<?php

namespace App\Services;

use App\Jobs\DeliverNotificationJob;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryRetryService
{
    public function retry(NotificationDelivery $delivery): bool
    {
        return DB::transaction(function () use ($delivery): bool {
            $locked = NotificationDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->status === 'sent') {
                return false;
            }
            $locked->update(['status' => 'pending', 'safe_error_message' => null, 'error_category' => null]);
            DeliverNotificationJob::dispatch($locked->id)->afterCommit();

            return true;
        });
    }
}
