<?php

namespace App\Repositories\Absensi\AdminWeb\Setting\Permission;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

interface PermissionRepositoryInterface
{
    /** @param array{search?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return Collection<int, Permission> */
    public function all(): Collection;

    /**
     * @return array{total: int, in_use: int, system: int, role_assignments: int, user_assignments: int}
     */
    public function overview(): array;

    /** @param list<int> $ids
     * @return list<string>
     */
    public function namesByIds(array $ids): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Permission;

    /** @param array<string, mixed> $attributes */
    public function update(Permission $permission, array $attributes): Permission;

    public function delete(Permission $permission): void;
}
