<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Shift;

use App\Models\Pegawai;
use App\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftAssignmentService
{
    /** @param array<string, mixed> $filters */
    public function normalizeFilters(array $filters): array
    {
        $status = in_array($filters['status'] ?? null, ['assigned', 'unassigned'], true)
            ? $filters['status']
            : null;
        $shiftId = filter_var($filters['shift_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        return [
            'search' => trim((string) ($filters['search'] ?? '')) ?: null,
            'status' => $status,
            'shift_id' => $shiftId,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Pegawai::query()
            ->whereNotNull('user_id')
            ->with([
                'user:id,name,email,email_verified_at',
                'unitKerja:id,nama',
                'jabatan:id,nama',
                'shift:id,nama,jam_masuk,jam_keluar',
                'shiftAssignedBy:id,name',
            ])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('nama', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('unitKerja', fn ($query) => $query->where('nama', 'like', "%{$search}%"))
                        ->orWhereHas('jabatan', fn ($query) => $query->where('nama', 'like', "%{$search}%"));
                });
            })
            ->when(
                ($filters['status'] ?? null) === 'assigned',
                fn ($query) => $query->whereNotNull('shift_id'),
            )
            ->when(
                ($filters['status'] ?? null) === 'unassigned',
                fn ($query) => $query->whereNull('shift_id'),
            )
            ->when(
                $filters['shift_id'] ?? null,
                fn ($query, int $shiftId) => $query->where('shift_id', $shiftId),
            )
            ->orderBy('nama')
            ->orderBy('nik')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Pegawai $pegawai) => [
                'id' => (int) $pegawai->id,
                'nik' => $pegawai->nik,
                'nama' => $pegawai->nama,
                'unit' => $pegawai->unitKerja?->nama,
                'jabatan' => $pegawai->jabatan?->nama,
                'user' => [
                    'name' => $pegawai->user?->name,
                    'email' => $pegawai->user?->email,
                    'active' => $pegawai->user?->email_verified_at !== null,
                ],
                'shift_id' => $pegawai->shift_id,
                'shift' => $pegawai->shift ? $this->shiftRow($pegawai->shift) : null,
                'assigned_at' => $pegawai->shift_assigned_at?->format('d M Y, H:i'),
                'assigned_by' => $pegawai->shiftAssignedBy?->name,
            ]);
    }

    /** @return array{total: int, assigned: int, unassigned: int} */
    public function overview(): array
    {
        $query = Pegawai::query()->whereNotNull('user_id');
        $total = (clone $query)->count();
        $assigned = (clone $query)->whereNotNull('shift_id')->count();

        return [
            'total' => $total,
            'assigned' => $assigned,
            'unassigned' => $total - $assigned,
        ];
    }

    /** @return array<int, array{id: int, nama: string, jam_masuk: string, jam_keluar: string}> */
    public function shiftOptions(): array
    {
        return Shift::query()
            ->orderBy('jam_masuk')
            ->orderBy('nama')
            ->get(['id', 'nama', 'jam_masuk', 'jam_keluar'])
            ->map(fn (Shift $shift) => $this->shiftRow($shift))
            ->all();
    }

    /** @param list<int> $pegawaiIds */
    public function assign(array $pegawaiIds, ?int $shiftId, int $assignedBy): int
    {
        return DB::transaction(fn () => Pegawai::query()
            ->whereNotNull('user_id')
            ->whereKey($pegawaiIds)
            ->update([
                'shift_id' => $shiftId,
                'shift_assigned_at' => $shiftId === null ? null : now(),
                'shift_assigned_by' => $shiftId === null ? null : $assignedBy,
                'updated_at' => now(),
            ]));
    }

    /** @return array{id: int, nama: string, jam_masuk: string, jam_keluar: string} */
    private function shiftRow(Shift $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'nama' => $shift->nama,
            'jam_masuk' => substr((string) $shift->jam_masuk, 0, 5),
            'jam_keluar' => substr((string) $shift->jam_keluar, 0, 5),
        ];
    }
}
