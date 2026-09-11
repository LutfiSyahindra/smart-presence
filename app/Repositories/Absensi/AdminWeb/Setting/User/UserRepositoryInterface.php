<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\User;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    /**
     * @param  array{search?: string|null, role?: string|null}  $filters
     */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /**
     * @return array{total: int, verified: int, with_roles: int, with_direct_permissions: int}
     */
    public function overview(): array;

    public function findOrFail(int $id): User;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): User;

    /** @param array<string, mixed> $attributes */
    public function update(User $user, array $attributes): User;

    public function delete(User $user): void;

    /** @param list<string> $roles */
    public function syncRoles(User $user, array $roles): void;

    /** @param list<string> $permissions */
    public function syncPermissions(User $user, array $permissions): void;

    public function countWithRole(string $role): int;
}
