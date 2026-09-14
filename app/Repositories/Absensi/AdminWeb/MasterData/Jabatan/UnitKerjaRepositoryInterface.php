<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\UnitKerja;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface UnitKerjaRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 8): LengthAwarePaginator;

    /** @return array{total_units: int, active_units: int, total_jabatans: int, active_jabatans: int, added_this_month: int} */
    public function overview(): array;

    /** @return Collection<int, UnitKerja> */
    public function options(): Collection;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): UnitKerja;

    /** @param array<string, mixed> $attributes */
    public function update(UnitKerja $unitKerja, array $attributes): UnitKerja;

    public function delete(UnitKerja $unitKerja): void;
}
