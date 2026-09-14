<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Jabatan;

use App\Models\Jabatan;
use App\Models\UnitKerja;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\JabatanRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\UnitKerjaRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UnitJabatanService
{
    public function __construct(
        private readonly UnitKerjaRepositoryInterface $units,
        private readonly JabatanRepositoryInterface $jabatans,
    ) {}

    /** @param array{search?: string|null, status?: string|null} $filters */
    public function paginateUnits(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;
        $filters['status'] = in_array($filters['status'] ?? null, ['active', 'inactive'], true)
            ? $filters['status']
            : null;

        return $this->units->paginate($filters)->through(fn (UnitKerja $unit) => [
            'id' => $unit->id,
            'kode' => $unit->kode,
            'nama' => $unit->nama,
            'deskripsi' => $unit->deskripsi,
            'aktif' => $unit->aktif,
            'jabatans_count' => $unit->jabatans_count,
            'jabatans_aktif_count' => $unit->jabatans_aktif_count,
            'updated_at' => $unit->updated_at?->format('d M Y, H:i'),
            'jabatans' => $unit->jabatans->map(fn (Jabatan $jabatan) => $this->jabatanData($jabatan))->values(),
        ]);
    }

    /** @return array{total_units: int, active_units: int, total_jabatans: int, active_jabatans: int, added_this_month: int} */
    public function overview(): array
    {
        return $this->units->overview();
    }

    /** @return Collection<int, array{id: int, kode: string, nama: string, aktif: bool}> */
    public function unitOptions(): Collection
    {
        return $this->units->options()->map(fn (UnitKerja $unit) => [
            'id' => $unit->id,
            'kode' => $unit->kode,
            'nama' => $unit->nama,
            'aktif' => $unit->aktif,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createUnit(array $data): UnitKerja
    {
        return DB::transaction(function () use ($data) {
            $jabatans = $data['jabatans'];
            unset($data['jabatans']);

            $unit = $this->units->create($data);

            foreach ($jabatans as $jabatan) {
                $this->jabatans->create([...$jabatan, 'unit_kerja_id' => $unit->id]);
            }

            return $unit->load('jabatans');
        });
    }

    /** @param array<string, mixed> $data */
    public function updateUnit(UnitKerja $unitKerja, array $data): UnitKerja
    {
        return $this->units->update($unitKerja, $data);
    }

    public function deleteUnit(UnitKerja $unitKerja): void
    {
        $this->units->delete($unitKerja);
    }

    /** @param array<string, mixed> $data */
    public function createJabatan(array $data): Jabatan
    {
        return $this->jabatans->create($data);
    }

    /** @param list<array<string, mixed>> $items */
    public function createManyJabatans(int $unitKerjaId, array $items): int
    {
        return DB::transaction(function () use ($unitKerjaId, $items) {
            foreach ($items as $item) {
                $this->jabatans->create([...$item, 'unit_kerja_id' => $unitKerjaId]);
            }

            return count($items);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateJabatan(Jabatan $jabatan, array $data): Jabatan
    {
        return $this->jabatans->update($jabatan, $data);
    }

    public function deleteJabatan(Jabatan $jabatan): void
    {
        $this->jabatans->delete($jabatan);
    }

    /** @return array{id: int, unit_kerja_id: int|null, kode: string, nama: string, deskripsi: string|null, aktif: bool, updated_at: string|null} */
    private function jabatanData(Jabatan $jabatan): array
    {
        return [
            'id' => $jabatan->id,
            'unit_kerja_id' => $jabatan->unit_kerja_id,
            'kode' => $jabatan->kode,
            'nama' => $jabatan->nama,
            'deskripsi' => $jabatan->deskripsi,
            'aktif' => $jabatan->aktif,
            'updated_at' => $jabatan->updated_at?->format('d M Y, H:i'),
        ];
    }
}
