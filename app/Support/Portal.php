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
        return $request->user() !== null && CutiAccess::allows($request->user());
    }

    public static function homeRouteName(Request $request): string
    {
        if (self::current($request) === self::ADMIN) {
            if (self::canAccessAdmin($request)) {
                return 'absensi.admin-web.dashboard';
            }

            if (self::canAccessCuti($request)) {
                return 'absensi.admin-web.cuti.index';
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
