<?php

namespace App\Services\Notifications;

use App\Models\MobilePushDevice;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use RuntimeException;
use Throwable;

class MobilePushService
{
    public function configured(string $platform): bool
    {
        return match ($platform) {
            'android' => is_readable(config('mobilepush.fcm.credentials') ?? ''),
            'ios' => filled(config('mobilepush.apns.key_id')) && filled(config('mobilepush.apns.team_id'))
                && filled(config('mobilepush.apns.topic')) && is_readable(config('mobilepush.apns.private_key') ?? ''),
            default => false,
        };
    }

    public function send(DatabaseNotification $notification): void
    {
        $user = $notification->notifiable;
        if (! $user instanceof User || ! $user->hasVerifiedEmail() || ! $user->can(AccessPermission::MOBILE_ACCESS)
            || ! $user->can(AccessPermission::MOBILE_NOTIFICATIONS) || ! isset($notification->data['mobile_url'])) {
            return;
        }
        $data = [
            'id' => $notification->id,
            'url' => route('absensi.mobile.notifikasi.open', $notification->id, absolute: false),
        ];
        // OS push providers receive only a generic status and an opaque notification ID.
        // Employee names, leave types, dates and reasons stay behind authenticated routes.
        $alert = ['title' => 'RSARSY Presence', 'body' => match ($notification->data['event'] ?? '') {
            'approved' => 'Pengajuan telah disetujui. Buka aplikasi untuk melihat detail.',
            'rejected' => 'Pengajuan telah ditolak. Buka aplikasi untuk melihat detail.',
            default => 'Ada pembaruan pengajuan. Buka aplikasi untuk melihat detail.',
        }];
        $failed = false;
        foreach (MobilePushDevice::query()->where('user_id', $user->id)->get() as $device) {
            if (! $this->configured($device->platform)) {
                continue;
            }
            try {
                if ($device->platform === 'android') {
                    $this->sendFcm($device, $alert, $data);
                } else {
                    $this->sendApns($device, $alert, $data);
                }
            } catch (Throwable) {
                // Continue other devices. Never include device tokens or credentials in queue errors.
                $failed = true;
            }
        }
        if ($failed) {
            throw new RuntimeException('Pengiriman push mobile gagal; pengiriman akan dicoba kembali.');
        }
    }

    private function sendFcm(MobilePushDevice $device, array $alert, array $data): void
    {
        $credentials = json_decode(file_get_contents(config('mobilepush.fcm.credentials')), true, flags: JSON_THROW_ON_ERROR);
        $cacheKey = 'mobilepush:fcm:'.hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
        $token = Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials) {
            $assertion = $this->jwt($credentials['private_key'], 'RS256', [
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => time(), 'exp' => time() + 3600,
            ]);
            $response = Http::asForm()->timeout(15)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Otorisasi FCM gagal.');
            }

            return $response->json('access_token');
        });
        $project = rawurlencode($credentials['project_id']);
        $response = Http::withToken($token)->timeout(15)->withoutRedirecting()
            ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", ['message' => [
                'token' => $device->token, 'notification' => $alert, 'data' => $data,
                'android' => ['priority' => 'HIGH', 'ttl' => '86400s', 'notification' => [
                    'channel_id' => 'pengajuan', 'tag' => $data['id'], 'sound' => 'default', 'icon' => 'ic_stat_notification',
                ]],
            ]]);
        $unregistered = collect($response->json('error.details', []))->contains(fn ($detail) => ($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
            && ($detail['errorCode'] ?? '') === 'UNREGISTERED');
        if ($unregistered) {
            $device->delete();
        } elseif (! $response->successful()) {
            if ($response->status() === 401) {
                Cache::forget($cacheKey);
            }
            throw new RuntimeException('Pengiriman FCM gagal.');
        }
    }

    private function sendApns(MobilePushDevice $device, array $alert, array $data): void
    {
        $key = file_get_contents(config('mobilepush.apns.private_key'));
        $keyId = config('mobilepush.apns.key_id');
        $team = config('mobilepush.apns.team_id');
        $cacheKey = 'mobilepush:apns:'.hash('sha256', $key.$keyId.$team);
        $token = Cache::remember($cacheKey, now()->addMinutes(50), fn () => $this->jwt($key, 'ES256', ['iss' => $team, 'iat' => time()], ['kid' => $keyId]));
        $host = config('mobilepush.apns.sandbox') ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $response = Http::withToken($token)->withOptions(['version' => '2.0'])->timeout(15)->withoutRedirecting()
            ->withHeaders([
                'apns-topic' => config('mobilepush.apns.topic'), 'apns-push-type' => 'alert',
                'apns-priority' => '10', 'apns-expiration' => (string) (time() + 86400), 'apns-collapse-id' => $data['id'],
            ])->post("https://{$host}/3/device/{$device->token}", [
                'aps' => ['alert' => $alert, 'sound' => 'default', 'thread-id' => 'pengajuan'], ...$data,
            ]);
        if ($response->status() === 410 && $response->json('reason') === 'Unregistered') {
            $device->delete();
        } elseif (! $response->successful()) {
            if ($response->json('reason') === 'ExpiredProviderToken') {
                Cache::forget($cacheKey);
            }
            throw new RuntimeException('Pengiriman APNs gagal.');
        }
    }

    private function jwt(#[\SensitiveParameter] string $key, string $algorithm, array $payload, array $headers = []): string
    {
        // The JOSE implementation is also used by the existing Web Push transport.
        $builder = new JWSBuilder(new AlgorithmManager([$algorithm === 'ES256' ? new ES256 : new RS256]));
        $jws = $builder->create()->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addSignature(JWKFactory::createFromKey($key), ['alg' => $algorithm, 'typ' => 'JWT', ...$headers])->build();

        return (new CompactSerializer)->serialize($jws, 0);
    }
}
