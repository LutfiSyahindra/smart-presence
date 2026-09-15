<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PegawaiRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null, unit_kerja?: int|null} $filters */
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator;

    /** @return array{total: int, tetap: int, tidak_tetap: int, joined_this_year: int} */
    public function overview(): array;

    /** @param list<string> $phoneNumbers @return list<string> */
    public function existingPhoneNumbers(array $phoneNumbers): array;

    /** @param list<string> $emails @return list<string> */
    public function existingEmails(array $emails): array;

    /** @param list<string> $niks @return list<string> */
    public function existingNiks(array $niks): array;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Pegawai;

    /** @param array<string, mixed> $attributes */
    public function update(Pegawai $pegawai, array $attributes): Pegawai;

    public function delete(Pegawai $pegawai): void;
}
