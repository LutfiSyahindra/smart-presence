<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PegawaiRepository implements PegawaiRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Pegawai::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nama', 'like', "%{$search}%")
                        ->orWhere('nomor_telepon', 'like', "%{$search}%");
                });
            })
            ->when(
                ($filters['birthday'] ?? null) === 'this_month',
                fn ($query) => $query->whereMonth('tanggal_lahir', now()->month),
            )
            ->orderBy('nama')
            ->orderBy('tanggal_lahir')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overview(): array
    {
        $base = Pegawai::query();
        $birthDates = (clone $base)->pluck('tanggal_lahir');
        $averageAge = $birthDates->isEmpty()
            ? 0
            : (int) round($birthDates->average(fn ($date) => now()->diffInYears($date, true)));

        return [
            'total' => (clone $base)->count(),
            'birthdays_this_month' => (clone $base)->whereMonth('tanggal_lahir', now()->month)->count(),
            'added_this_month' => (clone $base)->where('created_at', '>=', now()->startOfMonth())->count(),
            'average_age' => $averageAge,
        ];
    }

    public function existingPhoneNumbers(array $phoneNumbers): array
    {
        return Pegawai::query()
            ->whereIn('nomor_telepon', $phoneNumbers)
            ->pluck('nomor_telepon')
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
