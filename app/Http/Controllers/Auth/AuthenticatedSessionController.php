<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\MobilePushDevice;
use App\Models\WebPushSubscription;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        $portal = Portal::rememberEntry($request);

        return Inertia::render('auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'loginUrl' => Portal::loginUrl($portal),
            'portal' => $portal,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        Portal::rememberEntry($request);

        $intended = $request->session()->get('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        if (Portal::current($request) === Portal::PRESENSI && is_string($path)
            && preg_match('#^/absensi/mobile/notifikasi/open/[a-zA-Z0-9-]+$#D', $path)) {
            $request->session()->forget('url.intended');

            return redirect($path);
        }

        return to_route(Portal::homeRouteName($request));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $portal = Portal::current($request);

        $subscriptionIds = array_filter([
            ...$request->session()->get('web_push_subscription_ids', []),
            $request->session()->get('web_push_subscription_id'),
        ]);
        WebPushSubscription::query()->whereKey($subscriptionIds)->where('user_id', $request->user()->id)->delete();

        if ($deviceId = $request->session()->get('mobile_push_device_id')) {
            MobilePushDevice::query()->whereKey($deviceId)->where('user_id', $request->user()->id)->delete();
        }
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(Portal::loginUrl($portal));
    }
}
