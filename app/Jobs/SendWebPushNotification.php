<?php

namespace App\Jobs;

use App\Services\Notifications\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

class SendWebPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WebPushService $push): void
    {
        $notification = DatabaseNotification::query()->find($this->notificationId);
        if ($notification) {
            $push->send($notification);
        }
    }
}
