<?php

namespace App\Repositories\Absensi\AdminWeb\Presensi;

use App\Models\Presensi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PresensiRepositoryInterface
{
    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 12): LengthAwarePaginator;

    /** @param array<string, mixed> $filters */
    public function overview(array $filters): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Presensi;

    /** @param array<string, mixed> $attributes */
    public function update(Presensi $presensi, array $attributes): Presensi;
}
