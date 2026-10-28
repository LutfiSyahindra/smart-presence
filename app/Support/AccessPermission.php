<?php

namespace App\Support;

final class AccessPermission
{
    public const ADMIN_ACCESS = 'admin.access';

    public const MOBILE_ACCESS = 'mobile.access';

    public const MOBILE_ACCOUNT = 'mobile.account';

    public const DASHBOARD_VIEW = 'dashboard.view';

    public const NOTIFICATIONS_VIEW = 'notifications.view';

    public const CUTI_VIEW = 'cuti.view';

    public const CUTI_CREATE = 'cuti.create';

    public const CUTI_APPROVE = 'cuti.approve';

    public const MOBILE_ATTENDANCE = 'mobile.attendance';

    public const MOBILE_CLOCK_IN = 'mobile.clock-in';

    public const MOBILE_CLOCK_OUT = 'mobile.clock-out';

    public const MOBILE_HISTORY = 'mobile.history';

    public const MOBILE_CUTI_VIEW = 'mobile.cuti.view';

    public const MOBILE_CUTI_CREATE = 'mobile.cuti.create';

    public const MOBILE_CUTI_CANCEL = 'mobile.cuti.cancel';

    public const MOBILE_CUTI_APPROVE = 'mobile.cuti.approve';

    public const MOBILE_NOTIFICATIONS = 'mobile.notifications';

    public const JABATAN_VIEW = 'jabatan.view';

    public const JABATAN_CREATE = 'jabatan.create';

    public const JABATAN_UPDATE = 'jabatan.update';

    public const JABATAN_DELETE = 'jabatan.delete';

    public const PEGAWAI_VIEW = 'pegawai.view';

    public const PEGAWAI_CREATE = 'pegawai.create';

    public const PEGAWAI_UPDATE = 'pegawai.update';

    public const PEGAWAI_DELETE = 'pegawai.delete';

    public const SHIFT_VIEW = 'shift.view';

    public const SHIFT_CREATE = 'shift.create';

    public const SHIFT_UPDATE = 'shift.update';

    public const SHIFT_DELETE = 'shift.delete';

    public const MASTER_CUTI_VIEW = 'master-cuti.view';

    public const MASTER_CUTI_CREATE = 'master-cuti.create';

    public const MASTER_CUTI_UPDATE = 'master-cuti.update';

    public const MASTER_CUTI_DELETE = 'master-cuti.delete';

    public const MASTER_CUTI_ASSIGN = 'master-cuti.assign';

    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_CREATE = 'roles.create';

    public const ROLES_UPDATE = 'roles.update';

    public const ROLES_DELETE = 'roles.delete';

    public const PERMISSIONS_VIEW = 'permissions.view';

    public const PERMISSIONS_CREATE = 'permissions.create';

    public const PERMISSIONS_UPDATE = 'permissions.update';

    public const PERMISSIONS_DELETE = 'permissions.delete';

    public const APP_RELEASES_VIEW = 'app-releases.view';

    public const APP_RELEASES_MANAGE = 'app-releases.manage';

    public const APP_RELEASES_PUBLISH = 'app-releases.publish';

    public const GEOLOCATION_VIEW = 'geolocation.view';

    public const GEOLOCATION_CREATE = 'geolocation.create';

    public const GEOLOCATION_UPDATE = 'geolocation.update';

    public const GEOLOCATION_DELETE = 'geolocation.delete';

    public const PRESENSI_VIEW = 'presensi.view';

    public const PRESENSI_MANAGE = 'presensi.manage';

    public const APPROVAL_LINE_VIEW = 'approval-line.view';

    public const APPROVAL_LINE_CREATE = 'approval-line.create';

    public const APPROVAL_LINE_UPDATE = 'approval-line.update';

    public const APPROVAL_LINE_DELETE = 'approval-line.delete';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN_ACCESS,
            self::MOBILE_ACCESS,
            self::MOBILE_ACCOUNT,
            ...self::additional(),
            self::JABATAN_VIEW,
            self::JABATAN_CREATE,
            self::JABATAN_UPDATE,
            self::JABATAN_DELETE,
            self::PEGAWAI_VIEW,
            self::PEGAWAI_CREATE,
            self::PEGAWAI_UPDATE,
            self::PEGAWAI_DELETE,
            self::SHIFT_VIEW,
            self::SHIFT_CREATE,
            self::SHIFT_UPDATE,
            self::SHIFT_DELETE,
            self::MASTER_CUTI_VIEW,
            self::MASTER_CUTI_CREATE,
            self::MASTER_CUTI_UPDATE,
            self::MASTER_CUTI_DELETE,
            self::MASTER_CUTI_ASSIGN,
            self::USERS_VIEW,
            self::USERS_CREATE,
            self::USERS_UPDATE,
            self::USERS_DELETE,
            self::ROLES_VIEW,
            self::ROLES_CREATE,
            self::ROLES_UPDATE,
            self::ROLES_DELETE,
            self::PERMISSIONS_VIEW,
            self::PERMISSIONS_CREATE,
            self::PERMISSIONS_UPDATE,
            self::PERMISSIONS_DELETE,
            self::APP_RELEASES_VIEW,
            self::APP_RELEASES_MANAGE,
            self::APP_RELEASES_PUBLISH,
            self::GEOLOCATION_VIEW,
            self::GEOLOCATION_CREATE,
            self::GEOLOCATION_UPDATE,
            self::GEOLOCATION_DELETE,
            self::PRESENSI_VIEW,
            self::PRESENSI_MANAGE,
            self::APPROVAL_LINE_VIEW,
            self::APPROVAL_LINE_CREATE,
            self::APPROVAL_LINE_UPDATE,
            self::APPROVAL_LINE_DELETE,
        ];
    }

    public static function isSystem(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** @return list<string> */
    public static function additional(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::NOTIFICATIONS_VIEW,
            self::CUTI_VIEW,
            self::CUTI_CREATE,
            self::CUTI_APPROVE,
            self::MOBILE_ATTENDANCE,
            self::MOBILE_CLOCK_IN,
            self::MOBILE_CLOCK_OUT,
            self::MOBILE_HISTORY,
            self::MOBILE_CUTI_VIEW,
            self::MOBILE_CUTI_CREATE,
            self::MOBILE_CUTI_CANCEL,
            self::MOBILE_CUTI_APPROVE,
            self::MOBILE_NOTIFICATIONS,
        ];
    }

    public static function platform(string $permission): string
    {
        return str_starts_with($permission, 'mobile.') ? 'mobile' : 'web';
    }

    /** @return list<string> */
    public static function mobile(): array
    {
        return array_values(array_filter(self::all(), fn (string $permission) => self::platform($permission) === 'mobile'));
    }
}
