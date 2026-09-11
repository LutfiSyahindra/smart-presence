<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\Jabatan;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\JabatanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JabatanService
{
    public function __construct(private readonly JabatanRepositoryInterface $jabatans) {}

    /** @param array{search?: string|null, status?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;
        $filters['status'] = in_array($filters['status'] ?? null, ['active', 'inactive'], true)
            ? $filters['status']
            : null;

        return $this->jabatans->paginate($filters)->through(fn (Jabatan $jabatan) => [
            'id' => $jabatan->id,
            'kode' => $jabatan->kode,
            'nama' => $jabatan->nama,
            'deskripsi' => $jabatan->deskripsi,
            'aktif' => $jabatan->aktif,
            'created_at' => $jabatan->created_at?->format('d M Y'),
            'updated_at' => $jabatan->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, active: int, inactive: int, added_this_month: int} */
    public function overview(): array
    {
        return $this->jabatans->overview();
    }

    /** @param array{kode: string, nama: string, deskripsi?: string|null, aktif: bool} $data */
    public function create(array $data): Jabatan
    {
        return $this->jabatans->create($data);
    }

    /**
     * @param  list<array{kode: string, nama: string, deskripsi?: string|null, aktif: bool}>  $items
     * @return Collection<int, Jabatan>
     */
    public function createMany(array $items): Collection
    {
        return DB::transaction(fn () => collect($items)
            ->map(fn (array $item) => $this->jabatans->create($item)));
    }

    /** @param array{kode: string, nama: string, deskripsi?: string|null, aktif: bool} $data */
    public function update(Jabatan $jabatan, array $data): Jabatan
    {
        return $this->jabatans->update($jabatan, $data);
    }

    public function delete(Jabatan $jabatan): void
    {
        $this->jabatans->delete($jabatan);
    }
}
