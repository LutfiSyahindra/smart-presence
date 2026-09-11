<?php

namespace App\Services\Absensi\AdminWeb\Setting\Role;

use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepositoryInterface;
use App\Services\Absensi\AdminWeb\Setting\AuthorizationFormDataService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleService
{
    private const SUPER_ADMIN = 'super-admin';

    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly AuthorizationFormDataService $formData,
    ) {}

    /** @param array{search?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;

        return $this->roles->paginate($filters)->through(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
            'is_system' => $role->name === self::SUPER_ADMIN,
            'created_at' => $role->created_at?->format('d M Y'),
        ]);
    }

    /** @return array<int, mixed> */
    public function permissionGroups(): array
    {
        return $this->formData->permissionGroups();
    }

    /**
     * @return array{total: int, in_use: int, with_permissions: int, user_assignments: int}
     */
    public function overview(): array
    {
        return $this->roles->overview();
    }

    /** @return array<string, mixed> */
    public function editData(Role $role): array
    {
        $role->load('permissions:id,name');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'permission_ids' => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'is_system' => $role->name === self::SUPER_ADMIN,
        ];
    }

    /** @param array{name: string, permission_ids?: list<int>} $data */
    public function create(array $data): Role
    {
        if ($data['name'] === self::SUPER_ADMIN) {
            $this->throwReservedRoleValidation();
        }

        return DB::transaction(function () use ($data) {
            $role = $this->roles->create(['name' => $data['name'], 'guard_name' => 'web']);
            $this->roles->syncPermissions($role, $this->permissions->namesByIds($data['permission_ids'] ?? []));

            return $role->load('permissions');
        });
    }

    /** @param array{name: string, permission_ids?: list<int>} $data */
    public function update(Role $role, array $data): Role
    {
        if ($role->name === self::SUPER_ADMIN && $data['name'] !== self::SUPER_ADMIN) {
            $this->throwReservedRoleValidation();
        }

        if ($role->name !== self::SUPER_ADMIN && $data['name'] === self::SUPER_ADMIN) {
            $this->throwReservedRoleValidation();
        }

        return DB::transaction(function () use ($role, $data) {
            $role = $this->roles->update($role, ['name' => $data['name'], 'guard_name' => 'web']);
            $permissionNames = $role->name === self::SUPER_ADMIN
                ? $this->permissions->all()->pluck('name')->all()
                : $this->permissions->namesByIds($data['permission_ids'] ?? []);
            $this->roles->syncPermissions($role, $permissionNames);

            return $role->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        if ($role->name === self::SUPER_ADMIN) {
            throw ValidationException::withMessages([
                'role' => 'Role super-admin adalah role sistem dan tidak dapat dihapus.',
            ]);
        }

        $this->roles->delete($role);
    }

    private function throwReservedRoleValidation(): never
    {
        throw ValidationException::withMessages([
            'name' => 'Nama super-admin dicadangkan untuk role sistem.',
        ]);
    }
}
