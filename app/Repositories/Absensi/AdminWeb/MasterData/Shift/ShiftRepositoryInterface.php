<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Shift;

use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ShiftRepositoryInterface
{
    /** @param array{search?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return array{total: int, morning: int, afternoon: int, night: int} */
    public function overview(): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Shift;

    /** @param array<string, mixed> $attributes */
    public function update(Shift $shift, array $attributes): Shift;

    public function delete(Shift $shift): void;
}
