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
            'jam_masuk' => substr((string) $shift->jam_masuk, 0, 5),
            'jam_keluar' => substr((string) $shift->jam_keluar, 0, 5),
            'created_at' => $shift->created_at?->format('d M Y'),
            'updated_at' => $shift->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, morning: int, afternoon: int, night: int} */
    public function overview(): array
    {
        return $this->shifts->overview();
    }

    /** @param array{nama: string, jam_masuk: string, jam_keluar: string} $data */
    public function create(array $data): Shift
    {
        return $this->shifts->create($data);
    }

    /**
     * @param  list<array{nama: string, jam_masuk: string, jam_keluar: string}>  $items
     * @return Collection<int, Shift>
     */
    public function createMany(array $items): Collection
    {
        return DB::transaction(fn () => collect($items)
            ->map(fn (array $item) => $this->shifts->create($item)));
    }

    /** @param array{nama: string, jam_masuk: string, jam_keluar: string} $data */
    public function update(Shift $shift, array $data): Shift
    {
        return $this->shifts->update($shift, $data);
    }

    public function delete(Shift $shift): void
    {
        $this->shifts->delete($shift);
    }
}
