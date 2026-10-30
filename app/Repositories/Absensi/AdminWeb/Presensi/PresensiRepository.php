<?php

namespace App\Repositories\Absensi\AdminWeb\Presensi;

use App\Models\Pegawai;
use App\Models\Presensi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class PresensiRepository implements PresensiRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->rosterQuery($filters)
            ->with(['unitKerja:id,nama', 'jabatan:id,nama'])
            ->orderBy('pegawais.nama')
            ->orderByDesc('presensis.tanggal_presensi')
            ->orderByDesc('presensis.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function overview(array $filters): array
    {
        $query = $this->attendanceQuery(array_merge($filters, ['status' => null]));

        return [
            'total' => (clone $query)->count(),
            'present' => (clone $query)->accepted()->where('is_day_off', false)->whereNotNull('clock_in_at')->count(),
            'late' => $this->applyStatus((clone $query), 'late')->count(),
            'overtime' => (clone $query)->where('overtime_minutes', '>', 0)->count(),
            'day_off' => (clone $query)->where('is_day_off', true)->count(),
        ];
    }

    public function create(array $attributes): Presensi
    {
        return Presensi::query()->create($attributes);
    }

    public function update(Presensi $presensi, array $attributes): Presensi
    {
        $presensi->update($attributes);

        return $presensi->refresh();
    }

    /** @param array<string, mixed> $filters */
    private function rosterQuery(array $filters): Builder
    {
        $query = Pegawai::query()
            ->whereHas('user')
            ->leftJoin('presensis', function (JoinClause $join) use ($filters) {
                $join->on('presensis.pegawai_id', '=', 'pegawais.id')
                    ->whereBetween('presensis.tanggal_presensi', [$filters['date_from'], $filters['date_to']]);
            })
            ->select('pegawais.*')
            ->addSelect('presensis.id as presensi_record_id')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('pegawais.nama', 'like', "%{$search}%")
                        ->orWhere('pegawais.nik', 'like', "%{$search}%")
                        ->orWhereHas('unitKerja', fn (Builder $query) => $query->where('nama', 'like', "%{$search}%"))
                        ->orWhereHas('jabatan', fn (Builder $query) => $query->where('nama', 'like', "%{$search}%"));
                });
            });

        return $this->applyRosterStatus($query, $filters['status'] ?? null);
    }

    /** @param array<string, mixed> $filters */
    private function attendanceQuery(array $filters): Builder
    {
        $query = Presensi::query()
            ->whereHas('pegawai.user')
            ->whereBetween('tanggal_presensi', [$filters['date_from'], $filters['date_to']])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->whereHas('pegawai', function (Builder $query) use ($search) {
                    $query->where(function (Builder $query) use ($search) {
                        $query->where('nama', 'like', "%{$search}%")
                            ->orWhere('nik', 'like', "%{$search}%")
                            ->orWhereHas('unitKerja', fn (Builder $query) => $query->where('nama', 'like', "%{$search}%"))
                            ->orWhereHas('jabatan', fn (Builder $query) => $query->where('nama', 'like', "%{$search}%"));
                    });
                });
            });

        return $this->applyStatus($query, $filters['status'] ?? null);
    }

    private function applyRosterStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'approval-pending' => $query->where(fn ($query) => $query->where('presensis.clock_in_approval_status', 'pending')->orWhere('presensis.clock_out_approval_status', 'pending'))
                ->where(fn ($query) => $query->whereNull('presensis.clock_in_approval_status')->orWhere('presensis.clock_in_approval_status', '!=', 'rejected'))
                ->where(fn ($query) => $query->whereNull('presensis.clock_out_approval_status')->orWhere('presensis.clock_out_approval_status', '!=', 'rejected')),
            'approval-rejected' => $query->where(fn ($query) => $query->where('presensis.clock_in_approval_status', 'rejected')->orWhere('presensis.clock_out_approval_status', 'rejected')),
            'present' => Presensi::constrainAccepted($query)->where('presensis.is_day_off', false)->whereNotNull('presensis.clock_in_at'),
            'late' => Presensi::constrainArrivalStatus($query, true),
            'on-time' => Presensi::constrainArrivalStatus($query, false),
            'incomplete' => $query->where('presensis.is_day_off', false)
                ->where(fn (Builder $query) => $query
                    ->whereNull('presensis.clock_in_at')
                    ->orWhereNull('presensis.clock_out_at')),
            'overtime' => $query->where('presensis.overtime_minutes', '>', 0),
            'day-off' => $query->where('presensis.is_day_off', true),
            default => $query,
        };
    }

    private function applyStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'approval-pending' => $query->where(fn ($query) => $query->where('presensis.clock_in_approval_status', 'pending')->orWhere('presensis.clock_out_approval_status', 'pending'))
                ->where(fn ($query) => $query->whereNull('presensis.clock_in_approval_status')->orWhere('presensis.clock_in_approval_status', '!=', 'rejected'))
                ->where(fn ($query) => $query->whereNull('presensis.clock_out_approval_status')->orWhere('presensis.clock_out_approval_status', '!=', 'rejected')),
            'approval-rejected' => $query->where(fn ($query) => $query->where('presensis.clock_in_approval_status', 'rejected')->orWhere('presensis.clock_out_approval_status', 'rejected')),
            'present' => $query->accepted()->where('is_day_off', false)->whereNotNull('clock_in_at'),
            'late' => Presensi::constrainArrivalStatus($query, true),
            'on-time' => Presensi::constrainArrivalStatus($query, false),
            'incomplete' => $query->where('is_day_off', false)
                ->where(fn (Builder $query) => $query->whereNull('clock_in_at')->orWhereNull('clock_out_at')),
            'overtime' => $query->where('overtime_minutes', '>', 0),
            'day-off' => $query->where('is_day_off', true),
            default => $query,
        };
    }
}
