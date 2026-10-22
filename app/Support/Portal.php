<?php

namespace App\Support;

use Illuminate\Http\Request;

final class Portal
{
    public const PRESENSI = 'presensi';

    public const ADMIN = 'admin';

    public const SESSION_KEY = 'active_portal';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRESENSI, self::ADMIN];
    }

    public static function fromEntryRequest(Request $request): string
    {
        $routePortal = $request->route('portal');

        if (is_string($routePortal) && in_array($routePortal, self::all(), true)) {
            return $routePortal;
        }

        return $request->is('absensi/admin-web', 'absensi/admin-web/*')
            ? self::ADMIN
            : self::PRESENSI;
    }

    public static function rememberEntry(Request $request): string
    {
        $portal = self::fromEntryRequest($request);
        self::remember($request, $portal);

        return $portal;
    }

    public static function remember(Request $request, string $portal): void
    {
        $request->session()->put(
            self::SESSION_KEY,
            in_array($portal, self::all(), true) ? $portal : self::PRESENSI,
        );
    }

    public static function current(Request $request): string
    {
        $portal = $request->session()->get(self::SESSION_KEY, self::PRESENSI);

        return is_string($portal) && in_array($portal, self::all(), true)
            ? $portal
            : self::PRESENSI;
    }

    public static function canAccessAdmin(Request $request): bool
    {
        return $request->user()?->can(AccessPermission::ADMIN_ACCESS) ?? false;
    }

    public static function canAccessCuti(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && CutiAccess::allows($user)
            && ($user->can(AccessPermission::CUTI_VIEW) || $user->can(AccessPermission::NOTIFICATIONS_VIEW));
    }

    public static function adminHomeRouteName(Request $request): ?string
    {
        if (self::canAccessAdmin($request)) {
            foreach ([
                AccessPermission::DASHBOARD_VIEW => 'dashboard',
                AccessPermission::PRESENSI_VIEW => 'presensi.index',
                AccessPermission::PRESENSI_MANAGE => 'presensi.manage',
                AccessPermission::APPROVAL_LINE_VIEW => 'approval-line.index',
                AccessPermission::PEGAWAI_VIEW => 'master-data.pegawai.index',
                AccessPermission::JABATAN_VIEW => 'master-data.jabatan.index',
                AccessPermission::MASTER_CUTI_VIEW => 'master-data.cuti.index',
                AccessPermission::SHIFT_VIEW => 'master-data.shift.index',
                AccessPermission::GEOLOCATION_VIEW => 'master-data.geolocation.index',
                AccessPermission::USERS_VIEW => 'setting.users.index',
                AccessPermission::ROLES_VIEW => 'setting.roles.index',
                AccessPermission::PERMISSIONS_VIEW => 'setting.permissions.index',
            ] as $permission => $route) {
                if ($request->user()->can($permission)) {
                    return 'absensi.admin-web.'.$route;
                }
            }
        }

        if (self::canAccessCuti($request)) {
            return $request->user()->can(AccessPermission::CUTI_VIEW)
                ? 'absensi.admin-web.notifikasi.index'
                : 'absensi.admin-web.notifikasi.all';
        }

        return null;
    }

    public static function homeRouteName(Request $request): string
    {
        if (self::current($request) === self::ADMIN) {
            if ($route = self::adminHomeRouteName($request)) {
                return $route;
            }

            self::remember($request, self::PRESENSI);
        }

        return 'absensi.mobile.home';
    }

    public static function homeUrl(Request $request): string
    {
        return route(self::homeRouteName($request), absolute: false);
    }

    public static function loginRouteName(string $portal): string
    {
        return $portal === self::ADMIN
            ? 'absensi.admin-web.login'
            : 'login';
    }

    public static function loginUrl(string $portal): string
    {
        return route(self::loginRouteName($portal), absolute: false);
    }
}
