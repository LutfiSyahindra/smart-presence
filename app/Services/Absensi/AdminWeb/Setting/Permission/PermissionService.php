<?php

namespace App\Services\Absensi\AdminWeb\Setting\Permission;

use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepositoryInterface;
use App\Support\AccessPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class PermissionService
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
        private readonly RoleRepositoryInterface $roles,
    ) {}

    /** @param array{search?: string|null, platform?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;

        return $this->permissions->paginate($filters)->through(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'platform' => AccessPermission::platform($permission->name),
            'group' => str($permission->name)->before('.')->headline()->toString(),
            'roles_count' => $permission->roles_count,
            'users_count' => $permission->users_count,
            'is_system' => AccessPermission::isSystem($permission->name),
            'created_at' => $permission->created_at?->format('d M Y'),
        ]);
    }

    /**
     * @return array{total: int, in_use: int, system: int, role_assignments: int, user_assignments: int}
     */
    public function overview(): array
    {
        return $this->permissions->overview();
    }

    /** @param array{name: string} $data */
    public function create(array $data): Permission
    {
        return DB::transaction(function () use ($data) {
            $permission = $this->permissions->create(['name' => $data['name'], 'guard_name' => 'web']);
            $this->roles->findByName('super-admin')?->givePermissionTo($permission);

            return $permission;
        });
    }

    /** @param array{name: string} $data */
    public function update(Permission $permission, array $data): Permission
    {
        if (AccessPermission::isSystem($permission->name) && $permission->name !== $data['name']) {
            $this->throwSystemPermissionValidation('Nama permission sistem tidak dapat diubah.');
        }

        return $this->permissions->update($permission, ['name' => $data['name'], 'guard_name' => 'web']);
    }

    public function delete(Permission $permission): void
    {
        if (AccessPermission::isSystem($permission->name)) {
            $this->throwSystemPermissionValidation('Permission sistem tidak dapat dihapus.');
        }

        $this->permissions->delete($permission);
    }

    private function throwSystemPermissionValidation(string $message): never
    {
        throw ValidationException::withMessages(['permission' => $message]);
    }
}
