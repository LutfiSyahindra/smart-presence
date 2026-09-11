<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\Role;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class RoleRepository implements RoleRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->withCount(['permissions', 'users'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function all(): Collection
    {
        return Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array{total: int, in_use: int, with_permissions: int, user_assignments: int}
     */
    public function overview(): array
    {
        $roles = Role::query()
            ->select('id')
            ->where('guard_name', 'web')
            ->withCount(['permissions', 'users'])
            ->get();

        return [
            'total' => $roles->count(),
            'in_use' => $roles->where('users_count', '>', 0)->count(),
            'with_permissions' => $roles->where('permissions_count', '>', 0)->count(),
            'user_assignments' => (int) $roles->sum('users_count'),
        ];
    }

    public function namesByIds(array $ids): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereIntegerInRaw('id', $ids)
            ->pluck('name')
            ->all();
    }

    public function create(array $attributes): Role
    {
        return Role::query()->create($attributes);
    }

    public function update(Role $role, array $attributes): Role
    {
        $role->update($attributes);

        return $role->refresh();
    }

    public function syncPermissions(Role $role, array $permissions): void
    {
        $role->syncPermissions($permissions);
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function findByName(string $name): ?Role
    {
        return Role::query()->where('guard_name', 'web')->where('name', $name)->first();
    }
}
