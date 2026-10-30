<?php

namespace App\Services\Notifications;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class NotificationRealtimeService
{
    public function changed(int $userId): void
    {
        if (! config('mobile-realtime.enabled')) {
            return;
        }
        DB::afterCommit(function () use ($userId) {
            try {
                // Only the authenticated owner can subscribe. No personal data is broadcast.
                Broadcast::connection('reverb')->broadcast([new PrivateChannel('notifications.'.$userId)],
                    'notifications.changed', ['version' => (string) Str::ulid()]);
            } catch (Throwable $exception) {
                Log::warning('Realtime notifikasi tidak tersedia; inbox dan push tetap tersedia.', ['exception' => $exception::class]);
            }
        });
    }
}
