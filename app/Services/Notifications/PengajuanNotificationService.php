<?php

namespace App\Services\Notifications;

use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\User;
use App\Notifications\PengajuanNotification;
use App\Support\AccessPermission;
use Illuminate\Support\Str;

class PengajuanNotificationService
{
    /** @param iterable<User> $recipients */
    public function send(iterable $recipients, array $payload): void
    {
        foreach (collect($recipients)->unique('id') as $user) {
            $notification = new PengajuanNotification($payload);
            $notification->id = (string) Str::uuid();
            $user->notify($notification);
            app(NotificationRealtimeService::class)->changed($user->id);

            // The inbox is saved in the submission transaction. Push starts only after commit.
            SendWebPushNotification::dispatch($notification->id)->afterCommit();
            if (isset($payload['mobile_url']) && $user->can(AccessPermission::MOBILE_ACCESS) && $user->can(AccessPermission::MOBILE_NOTIFICATIONS)) {
                SendMobilePushNotification::dispatch($notification->id)->afterCommit();
            }
        }
    }
}
