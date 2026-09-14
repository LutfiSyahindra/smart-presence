<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\Jabatan;
use App\Models\UnitKerja;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class UnitKerjaRepository implements UnitKerjaRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 8): LengthAwarePaginator
    {
        return UnitKerja::query()
            ->with('jabatans')
            ->withCount([
                'jabatans',
                'jabatans as jabatans_aktif_count' => fn ($query) => $query->where('aktif', true),
            ])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%")
                        ->orWhereHas('jabatans', function ($query) use ($search) {
                            $query->where('kode', 'like', "%{$search}%")
                                ->orWhere('nama', 'like', "%{$search}%")
                                ->orWhere('deskripsi', 'like', "%{$search}%");
                        });
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('aktif', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('aktif', false))
            ->orderByDesc('aktif')
            ->orderBy('nama')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overview(): array
    {
        return [
            'total_units' => UnitKerja::query()->count(),
            'active_units' => UnitKerja::query()->where('aktif', true)->count(),
            'total_jabatans' => Jabatan::query()->count(),
            'active_jabatans' => Jabatan::query()->where('aktif', true)->count(),
            'added_this_month' => UnitKerja::query()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    public function options(): Collection
    {
        return UnitKerja::query()
            ->orderByDesc('aktif')
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama', 'aktif']);
    }

    public function create(array $attributes): UnitKerja
    {
        return UnitKerja::query()->create($attributes);
    }

    public function update(UnitKerja $unitKerja, array $attributes): UnitKerja
    {
        $unitKerja->update($attributes);

        return $unitKerja->refresh();
    }

    public function delete(UnitKerja $unitKerja): void
    {
        $unitKerja->delete();
    }
}
