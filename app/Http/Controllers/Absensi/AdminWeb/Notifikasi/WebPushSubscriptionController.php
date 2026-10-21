<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\WebPushSubscription;
use App\Services\Notifications\WebPushService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function store(Request $request, WebPushService $push): JsonResponse
    {
        abort_unless($push->configured(), 503, 'Push notification belum dikonfigurasi.');
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail) {
                $url = parse_url($value);
                $host = strtolower($url['host'] ?? '');
                $allowed = $host === 'fcm.googleapis.com'
                    || $host === 'updates.push.services.mozilla.com'
                    || str_ends_with($host, '.push.services.mozilla.com')
                    || str_ends_with($host, '.notify.windows.com')
                    || $host === 'web.push.apple.com'
                    || str_ends_with($host, '.push.apple.com');
                if (! $allowed || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
                    || (isset($url['port']) && $url['port'] !== 443)) {
                    $fail('Endpoint push browser tidak valid.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+={0,2}$/', 'size:87'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+={0,2}$/', 'size:22'],
            'contentEncoding' => ['sometimes', 'in:aes128gcm,aesgcm'],
        ]);
        $key = base64_decode(strtr($data['keys']['p256dh'], '-_', '+/'), true);
        $auth = base64_decode(strtr($data['keys']['auth'], '-_', '+/'), true);
        abort_unless($key !== false && strlen($key) === 65 && ord($key[0]) === 4 && $auth !== false && strlen($auth) === 16, 422);

        $subscription = WebPushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'audience' => $request->routeIs('absensi.mobile.*') ? 'mobile' : 'admin',
            'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
        ]);
        $subscriptionIds = array_filter([
            ...$request->session()->get('web_push_subscription_ids', []),
            $request->session()->get('web_push_subscription_id'), $subscription->id,
        ]);
        $request->session()->put('web_push_subscription_ids', array_values(array_unique($subscriptionIds)));
        $request->session()->put('web_push_subscription_id', $subscription->id);

        return response()->json(['success' => true]);
    }
}
