<?php

namespace App\Support;

final class AccessPermission
{
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

    public const GEOLOCATION_VIEW = 'geolocation.view';

    public const GEOLOCATION_CREATE = 'geolocation.create';

    public const GEOLOCATION_UPDATE = 'geolocation.update';

    public const GEOLOCATION_DELETE = 'geolocation.delete';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
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
            self::GEOLOCATION_VIEW,
            self::GEOLOCATION_CREATE,
            self::GEOLOCATION_UPDATE,
            self::GEOLOCATION_DELETE,
        ];
    }

    public static function isSystem(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
