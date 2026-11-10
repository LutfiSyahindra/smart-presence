<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Shift;

use App\Models\Shift;
use App\Repositories\Absensi\AdminWeb\MasterData\Shift\ShiftRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(private readonly ShiftRepositoryInterface $shifts) {}

    /** @param array{search?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;

        return $this->shifts->paginate($filters)->through(fn (Shift $shift) => [
            'id' => $shift->id,
            'nama' => $shift->nama,
            'is_day_off' => $shift->is_day_off,
            'jam_masuk' => substr((string) $shift->jam_masuk, 0, 5),
            'jam_keluar' => substr((string) $shift->jam_keluar, 0, 5),
            'libur_sabtu' => $shift->libur_sabtu,
            'libur_minggu' => $shift->libur_minggu,
            'libur_nasional' => $shift->libur_nasional,
            'libur_cuti_bersama' => $shift->libur_cuti_bersama,
            'created_at' => $shift->created_at?->format('d M Y'),
            'updated_at' => $shift->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, morning: int, afternoon: int, night: int, off: int} */
    public function overview(): array
    {
        return $this->shifts->overview();
    }

    /** @param array{nama: string, is_day_off?: bool, jam_masuk?: string|null, jam_keluar?: string|null} $data */
    public function create(array $data): Shift
    {
        return $this->shifts->create($this->attributes($data));
    }

    /**
     * @param  list<array{nama: string, is_day_off?: bool, jam_masuk?: string|null, jam_keluar?: string|null}>  $items
     * @return Collection<int, Shift>
     */
    public function createMany(array $items): Collection
    {
        return DB::transaction(fn () => collect($items)
            ->map(fn (array $item) => $this->shifts->create($this->attributes($item))));
    }

    /** @param array{nama: string, is_day_off?: bool, jam_masuk?: string|null, jam_keluar?: string|null} $data */
    public function update(Shift $shift, array $data): Shift
    {
        return $this->shifts->update($shift, $this->attributes($data));
    }

    private function attributes(array $data): array
    {
        if ($data['is_day_off'] ?? false) {
            return array_replace($data, [
                'jam_masuk' => null,
                'jam_keluar' => null,
                'libur_sabtu' => false,
                'libur_minggu' => false,
                'libur_nasional' => false,
                'libur_cuti_bersama' => false,
            ]);
        }

        return $data;
    }

    public function delete(Shift $shift): void
    {
        $this->shifts->delete($shift);
    }
}
