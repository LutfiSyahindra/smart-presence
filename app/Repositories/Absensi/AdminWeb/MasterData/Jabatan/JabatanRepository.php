<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\Jabatan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class JabatanRepository implements JabatanRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Jabatan::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('kode', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhere('deskripsi', 'like', "%{$search}%");
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
        $base = Jabatan::query();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('aktif', true)->count(),
            'inactive' => (clone $base)->where('aktif', false)->count(),
            'added_this_month' => (clone $base)->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    public function create(array $attributes): Jabatan
    {
        return Jabatan::query()->create($attributes);
    }

    public function update(Jabatan $jabatan, array $attributes): Jabatan
    {
        $jabatan->update($attributes);

        return $jabatan->refresh();
    }

    public function delete(Jabatan $jabatan): void
    {
        $jabatan->delete();
    }
}
