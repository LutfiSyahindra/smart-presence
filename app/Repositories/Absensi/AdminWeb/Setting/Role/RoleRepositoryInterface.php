<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\Role;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

interface RoleRepositoryInterface
{
    /** @param array{search?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return Collection<int, Role> */
    public function all(): Collection;

    /**
     * @return array{total: int, in_use: int, with_permissions: int, user_assignments: int}
     */
    public function overview(): array;

    /** @param list<int> $ids
     * @return list<string>
     */
    public function namesByIds(array $ids): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Role;

    /** @param array<string, mixed> $attributes */
    public function update(Role $role, array $attributes): Role;

    /** @param list<string> $permissions */
    public function syncPermissions(Role $role, array $permissions): void;

    public function delete(Role $role): void;

    public function findByName(string $name): ?Role;
}
