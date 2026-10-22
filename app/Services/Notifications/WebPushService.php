<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Support\AccessPermission;
use App\Support\CutiAccess;
use Illuminate\Notifications\DatabaseNotification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushService
{
    public function configured(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key')) && filled(config('webpush.subject'));
    }

    public function send(DatabaseNotification $notification): void
    {
        $user = $notification->notifiable;
        if (! $this->configured() || ! $user instanceof User || ! $user->hasVerifiedEmail()) {
            return;
        }

        $subscriptions = WebPushSubscription::query()->where('user_id', $user->id)->get()
            ->filter(fn (WebPushSubscription $subscription) => $subscription->audience === 'mobile'
                ? isset($notification->data['mobile_url']) && $user->can(AccessPermission::MOBILE_ACCESS) && $user->can(AccessPermission::MOBILE_NOTIFICATIONS)
                : CutiAccess::allows($user) && $user->can(AccessPermission::NOTIFICATIONS_VIEW));
        if ($subscriptions->isEmpty()) {
            return;
        }

        $client = $this->client();
        $failed = false;

        foreach ($subscriptions as $subscription) {
            $payload = json_encode([
                'id' => $notification->id,
                'title' => $subscription->audience === 'mobile' ? 'RSARSY Presence' : $notification->data['title'],
                'body' => $subscription->audience === 'mobile'
                    ? (($notification->data['event'] ?? '') === 'approved'
                        ? 'Pengajuan telah disetujui. Buka aplikasi untuk melihat detail.'
                        : 'Ada pembaruan pengajuan. Buka aplikasi untuk melihat detail.')
                    : $notification->data['message'],
                'url' => route($subscription->audience === 'mobile' ? 'absensi.mobile.notifikasi.open' : 'absensi.admin-web.notifikasi.open', $notification->id, absolute: false),
            ], JSON_THROW_ON_ERROR);
            $report = $client->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => ['p256dh' => $subscription->public_key, 'auth' => $subscription->auth_token],
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload);

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
            } elseif (! $report->isSuccess()) {
                $failed = true;
            }
        }

        if ($failed) {
            throw new RuntimeException('Pengiriman Web Push gagal; pengiriman akan dicoba kembali.');
        }
    }

    protected function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => config('webpush.subject'),
            'publicKey' => config('webpush.public_key'),
            'privateKey' => config('webpush.private_key'),
        ]], ['TTL' => 86400], 10, ['allow_redirects' => false]);
    }
}
