<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\User;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository implements UserRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles:id,name', 'permissions:id,name'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{total: int, verified: int, with_roles: int, with_direct_permissions: int}
     */
    public function overview(): array
    {
        return [
            'total' => User::query()->count(),
            'verified' => User::query()->whereNotNull('email_verified_at')->count(),
            'with_roles' => User::query()->whereHas('roles')->count(),
            'with_direct_permissions' => User::query()->whereHas('permissions')->count(),
        ];
    }

    public function findOrFail(int $id): User
    {
        return User::query()->with(['roles:id,name', 'permissions:id,name'])->findOrFail($id);
    }

    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $user->update($attributes);

        return $user->refresh();
    }

    public function delete(User $user): void
    {
        $user->delete();
    }

    public function syncRoles(User $user, array $roles): void
    {
        $user->syncRoles($roles);
    }

    public function syncPermissions(User $user, array $permissions): void
    {
        $user->syncPermissions($permissions);
    }

    public function countWithRole(string $role): int
    {
        return User::query()->role($role)->count();
    }
}
