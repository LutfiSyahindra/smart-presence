<?php

namespace App\Http\Middleware;

use App\Models\CutiRequest;
use App\Support\AccessPermission;
use App\Support\Portal;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'roles' => fn () => $request->user()?->getRoleNames()->values()->all() ?? [],
                'permissions' => fn () => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            'portal' => [
                'active' => fn () => Portal::current($request),
                'can_access_admin' => fn () => Portal::canAccessAdmin($request),
                'can_access_cuti' => fn () => Portal::canAccessCuti($request),
                'admin_home_url' => fn () => route(Portal::adminHomeRouteName($request) ?? 'absensi.mobile.home', absolute: false),
            ],
            'adminNotificationCounts' => fn () => Portal::canAccessCuti($request) ? [
                'unread_count' => $request->user()->can(AccessPermission::NOTIFICATIONS_VIEW)
                    ? $request->user()->unreadNotifications()->count() : 0,
                'pending_approval_count' => $request->user()->can(AccessPermission::CUTI_VIEW)
                    ? CutiRequest::query()->awaitingApprovalBy($request->user())->count() : 0,
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
