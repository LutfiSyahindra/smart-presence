<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\Permission;

use App\Support\AccessPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

class PermissionRepository implements PermissionRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->withCount(['roles', 'users'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function all(): Collection
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array{total: int, in_use: int, system: int, role_assignments: int, user_assignments: int}
     */
    public function overview(): array
    {
        $permissions = Permission::query()
            ->select(['id', 'name'])
            ->where('guard_name', 'web')
            ->withCount(['roles', 'users'])
            ->get();

        return [
            'total' => $permissions->count(),
            'in_use' => $permissions
                ->filter(fn (Permission $permission) => $permission->roles_count > 0 || $permission->users_count > 0)
                ->count(),
            'system' => $permissions->whereIn('name', AccessPermission::all())->count(),
            'role_assignments' => (int) $permissions->sum('roles_count'),
            'user_assignments' => (int) $permissions->sum('users_count'),
        ];
    }

    public function namesByIds(array $ids): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->whereIntegerInRaw('id', $ids)
            ->pluck('name')
            ->all();
    }

    public function create(array $attributes): Permission
    {
        return Permission::query()->create($attributes);
    }

    public function update(Permission $permission, array $attributes): Permission
    {
        $permission->update($attributes);

        return $permission->refresh();
    }

    public function delete(Permission $permission): void
    {
        $permission->delete();
    }
}
