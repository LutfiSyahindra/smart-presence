<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PegawaiRepositoryInterface
{
    /** @param array{search?: string|null, birthday?: string|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return array{total: int, birthdays_this_month: int, added_this_month: int, average_age: int} */
    public function overview(): array;

    /** @param list<string> $phoneNumbers @return list<string> */
    public function existingPhoneNumbers(array $phoneNumbers): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Pegawai;

    /** @param array<string, mixed> $attributes */
    public function update(Pegawai $pegawai, array $attributes): Pegawai;

    public function delete(Pegawai $pegawai): void;
}
