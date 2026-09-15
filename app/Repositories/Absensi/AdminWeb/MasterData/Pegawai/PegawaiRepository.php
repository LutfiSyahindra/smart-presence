<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PegawaiRepository implements PegawaiRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Pegawai::query()
            ->with(['unitKerja:id,nama', 'jabatan:id,nama', 'user:id,name,email,email_verified_at'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nama', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhere('profesi', 'like', "%{$search}%")
                        ->orWhere('nomor_telepon', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('unitKerja', fn ($query) => $query->where('nama', 'like', "%{$search}%"))
                        ->orWhereHas('jabatan', fn ($query) => $query->where('nama', 'like', "%{$search}%"));
                });
            })
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status_pegawai', $status),
            )
            ->when(
                $filters['unit_kerja'] ?? null,
                fn ($query, int $unitKerja) => $query->where('unit_kerja_id', $unitKerja),
            )
            ->orderBy('nama')
            ->orderBy('nik')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overview(): array
    {
        return [
            'total' => Pegawai::query()->count(),
            'tetap' => Pegawai::query()->where('status_pegawai', 'Tetap')->count(),
            'tidak_tetap' => Pegawai::query()->where('status_pegawai', 'Tidak Tetap')->count(),
            'joined_this_year' => Pegawai::query()->whereYear('tanggal_mulai_bekerja', now()->year)->count(),
        ];
    }

    public function existingPhoneNumbers(array $phoneNumbers): array
    {
        if ($phoneNumbers === []) {
            return [];
        }

        return Pegawai::query()
            ->whereIn('nomor_telepon', $phoneNumbers)
            ->pluck('nomor_telepon')
            ->all();
    }

    public function existingEmails(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return Pegawai::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();
    }

    public function existingNiks(array $niks): array
    {
        if ($niks === []) {
            return [];
        }

        return Pegawai::query()
            ->whereIn('nik', $niks)
            ->pluck('nik')
            ->all();
    }

    public function create(array $attributes): Pegawai
    {
        return Pegawai::query()->create($attributes);
    }

    public function update(Pegawai $pegawai, array $attributes): Pegawai
    {
        $pegawai->update($attributes);

        return $pegawai->refresh();
    }

    public function delete(Pegawai $pegawai): void
    {
        $pegawai->delete();
    }
}
