<?php

namespace App\Repositories\Absensi\AdminWeb\MasterData\Shift;

use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShiftRepository implements ShiftRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return Shift::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nama', 'like', "%{$search}%")
                        ->orWhere('jam_masuk', 'like', "%{$search}%")
                        ->orWhere('jam_keluar', 'like', "%{$search}%");
                });
            })
            ->orderBy('jam_masuk')
            ->orderBy('nama')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overview(): array
    {
        $base = Shift::query();

        return [
            'total' => (clone $base)->count(),
            'morning' => (clone $base)->whereTime('jam_masuk', '<', '12:00:00')->count(),
            'afternoon' => (clone $base)->whereTime('jam_masuk', '>=', '12:00:00')->whereTime('jam_masuk', '<', '18:00:00')->count(),
            'night' => (clone $base)->whereTime('jam_masuk', '>=', '18:00:00')->count(),
        ];
    }

    public function create(array $attributes): Shift
    {
        return Shift::query()->create($attributes);
    }

    public function update(Shift $shift, array $attributes): Shift
    {
        $shift->update($attributes);

        return $shift->refresh();
    }

    public function delete(Shift $shift): void
    {
        $shift->delete();
    }
}
