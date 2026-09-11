<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\Jabatan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface JabatanRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return array{total: int, active: int, inactive: int, added_this_month: int} */
    public function overview(): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Jabatan;

    /** @param array<string, mixed> $attributes */
    public function update(Jabatan $jabatan, array $attributes): Jabatan;

    public function delete(Jabatan $jabatan): void;
}
