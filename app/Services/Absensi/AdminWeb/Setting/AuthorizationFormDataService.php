<?php

namespace App\Services\Absensi\AdminWeb\Setting;

use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepositoryInterface;

class AuthorizationFormDataService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionRepositoryInterface $permissions,
    ) {}

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function roles(): array
    {
        return $this->roles->all()
            ->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group: string, permissions: array<int, array{id: int, name: string}>}>
     */
    public function permissionGroups(): array
    {
        return $this->permissions->all()
            ->groupBy(fn ($permission) => str($permission->name)->before('.')->toString())
            ->map(fn ($permissions, string $group) => [
                'group' => str($group)->headline()->toString(),
                'permissions' => $permissions
                    ->map(fn ($permission) => ['id' => (int) $permission->id, 'name' => $permission->name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
