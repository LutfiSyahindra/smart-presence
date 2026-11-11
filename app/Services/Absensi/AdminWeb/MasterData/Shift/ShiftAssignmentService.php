<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Shift;

use App\Models\CutiRequest;
use App\Models\HariLibur;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\Shift;
use App\Services\Absensi\ShiftHolidayService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftAssignmentService
{
    public function calendar(?string $month = null, ?string $selectedDate = null): array
    {
        $today = now()->toDateString();
        $month ??= substr($selectedDate ?? $today, 0, 7);
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth();
        $selectedDate ??= str_starts_with($today, $month.'-') ? $today : $start->toDateString();
        $employees = Pegawai::query()->whereHas('user')
            ->with(['shift', 'unitKerja:id,nama', 'jabatan:id,nama'])
            ->orderBy('nama')->orderBy('nik')->orderBy('id')
            ->get(['id', 'nama', 'nik', 'shift_id', 'unit_kerja_id', 'jabatan_id']);
        $records = Presensi::query()->whereHas('pegawai.user')->with('shift')
            ->whereBetween('tanggal_presensi', [$start->toDateString(), $end->toDateString()])
            ->get(['pegawai_id', 'tanggal_presensi', 'shift_id', 'is_day_off'])
            ->groupBy(fn (Presensi $record) => $record->tanggal_presensi->toDateString());
        $shifts = $employees->pluck('shift')->merge($records->flatten(1)->pluck('shift'))->filter()->unique('id');
        $calendar = HariLibur::query()->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $holidays = app(ShiftHolidayService::class);
        $days = [];
        $roster = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            $dailyRecords = $records->get($dateString, collect())->keyBy('pegawai_id');
            $dayOffReasons = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => $holidays->dayOffDetails($shift, $date, $calendar)['reason'] ?? null]);
            $day = ['date' => $dateString, 'day' => $date->day, 'working' => 0, 'day_off' => 0, 'unassigned' => 0];

            foreach ($employees as $employee) {
                $record = $dailyRecords->get($employee->id);
                $shift = $record?->shift ?? $employee->shift;
                $reason = $shift ? $dayOffReasons->get($shift->id) : null;
                $isDayOff = (bool) ($record?->is_day_off || $reason !== null);
                $day[$isDayOff ? 'day_off' : ($shift ? 'working' : 'unassigned')]++;

                if ($dateString === $selectedDate) {
                    $roster[] = [
                        'id' => (int) $employee->id,
                        'nama' => $employee->nama,
                        'nik' => $employee->nik,
                        'unit' => $employee->unitKerja?->nama,
                        'jabatan' => $employee->jabatan?->nama,
                        'source' => $record ? 'scheduled' : 'default',
                        'shift' => $shift ? $this->shiftRow($shift) : null,
                        'is_day_off' => $isDayOff,
                        'day_off_reason' => $isDayOff ? ($reason ?? 'Hari libur') : null,
                    ];
                }
            }
            $days[] = $day;
        }

        return [
            'month' => $month,
            'today' => $today,
            'selected_date' => $selectedDate,
            'total' => $employees->count(),
            'days' => $days,
            'employees' => $roster,
        ];
    }

    public function schedule(Pegawai $pegawai, ?string $month = null): array
    {
        $pegawai->loadMissing('shift');
        if ($month === null) {
            $nextDate = $pegawai->presensis()->whereDate('tanggal_presensi', '>=', now()->toDateString())
                ->orderBy('tanggal_presensi')->value('tanggal_presensi');
            $month = $nextDate ? CarbonImmutable::parse($nextDate)->format('Y-m') : now()->format('Y-m');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        $end = $start->endOfMonth();
        $records = Presensi::query()->with('shift')->where('pegawai_id', $pegawai->id)
            ->whereBetween('tanggal_presensi', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn (Presensi $record) => $record->tanggal_presensi->toDateString());
        $locks = $this->lockedDates($pegawai->id, $start->toDateString(), $end->toDateString(), $records);
        $calendar = HariLibur::query()->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get();
        $holidays = app(ShiftHolidayService::class);
        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $record = $records->get($date->toDateString());
            $shift = $record?->shift ?? $pegawai->shift;
            $dayOff = $holidays->dayOffDetails($shift, $date, $calendar);
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->day,
                'source' => $record ? 'scheduled' : 'default',
                'shift' => $shift ? $this->shiftRow($shift) : null,
                'is_day_off' => (bool) ($record?->is_day_off || $dayOff !== null),
                'day_off_reason' => $dayOff['reason'] ?? null,
                'locked_reason' => $locks[$date->toDateString()] ?? null,
            ];
        }

        return [
            'month' => $month,
            'today' => now()->toDateString(),
            'default_shift' => $pegawai->shift ? $this->shiftRow($pegawai->shift) : null,
            'days' => $days,
        ];
    }

    public function updateSchedule(Pegawai $pegawai, string $date, int $shiftId, int $actorId): void
    {
        DB::transaction(function () use ($pegawai, $date, $shiftId, $actorId) {
            $employee = Pegawai::query()->whereHas('user')->whereKey($pegawai->id)->lockForUpdate()->firstOrFail();
            $shift = Shift::query()->whereKey($shiftId)->lockForUpdate()->first();
            if (! $shift) {
                throw ValidationException::withMessages(['shift_id' => 'Shift yang dipilih sudah tidak tersedia.']);
            }
            $record = Presensi::query()->where('pegawai_id', $employee->id)->whereDate('tanggal_presensi', $date)->lockForUpdate()->first();
            $locks = $this->lockedDates($employee->id, $date, $date, collect($record ? [$record] : []));
            if (isset($locks[$date])) {
                throw ValidationException::withMessages(['date' => $locks[$date].' Jadwal tidak diubah.']);
            }

            $record ??= new Presensi(['pegawai_id' => $employee->id, 'tanggal_presensi' => $date]);
            $record->fill(['shift_id' => $shift->id, 'is_day_off' => (bool) $shift->is_day_off, 'updated_by' => $actorId])->save();
        });
    }

    /** @param Collection<int|string, Presensi> $records */
    private function lockedDates(int $pegawaiId, string $from, string $to, Collection $records): array
    {
        $locks = [];
        foreach ($records as $record) {
            if ($record->clock_in_at || $record->clock_out_at) {
                $locks[$record->tanggal_presensi->toDateString()] = 'Tanggal ini sudah memiliki clock in/out.';
            }
        }
        $leaves = CutiRequest::query()->where('pegawai_id', $pegawaiId)->where('status', 'approved')
            ->whereDate('tanggal_mulai', '<=', $to)->whereDate('tanggal_selesai', '>=', $from)
            ->get(['tanggal_mulai', 'tanggal_selesai']);
        foreach ($leaves as $leave) {
            $start = CarbonImmutable::parse(max($from, $leave->tanggal_mulai->toDateString()));
            $end = min($to, $leave->tanggal_selesai->toDateString());
            for ($date = $start; $date->toDateString() <= $end; $date = $date->addDay()) {
                $locks[$date->toDateString()] ??= 'Tanggal ini memiliki cuti yang disetujui.';
            }
        }
        $overtimes = LemburRequest::query()->where('pegawai_id', $pegawaiId)->whereIn('status', ['pending', 'approved'])
            ->whereDate('tanggal_lembur', '>=', $from)->whereDate('tanggal_lembur', '<=', $to)->get(['tanggal_lembur']);
        foreach ($overtimes as $overtime) {
            $locks[$overtime->tanggal_lembur->toDateString()] ??= 'Tanggal ini memiliki lembur aktif.';
        }
        $changes = PerubahanShiftRequest::query()->where('pegawai_id', $pegawaiId)->whereIn('status', ['pending', 'approved'])
            ->whereDate('tanggal_perubahan', '>=', $from)->whereDate('tanggal_perubahan', '<=', $to)->get(['tanggal_perubahan']);
        foreach ($changes as $change) {
            $locks[$change->tanggal_perubahan->toDateString()] ??= 'Tanggal ini memiliki pengajuan perubahan shift aktif.';
        }

        return $locks;
    }

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
        $today = CarbonImmutable::today();
        $calendar = HariLibur::query()->whereDate('tanggal', $today->toDateString())->orderBy('id')->get();
        $holidays = app(ShiftHolidayService::class);

        return Pegawai::query()
            ->whereNotNull('user_id')
            ->with([
                'user:id,name,email,email_verified_at',
                'unitKerja:id,nama',
                'jabatan:id,nama',
                'shift',
                'presensis' => fn ($query) => $query
                    ->whereDate('tanggal_presensi', $today->toDateString())
                    ->with('shift')
                    ->select(['id', 'pegawai_id', 'tanggal_presensi', 'shift_id', 'is_day_off']),
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
            ->through(function (Pegawai $pegawai) use ($today, $calendar, $holidays) {
                $record = $pegawai->presensis->first();
                $shift = $record?->shift ?? $pegawai->shift;
                $dayOff = $holidays->dayOffDetails($shift, $today, $calendar);
                $isDayOff = (bool) ($record?->is_day_off || $dayOff !== null);

                return [
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
                    'today_schedule' => [
                        'date' => $today->toDateString(),
                        'source' => $record ? 'scheduled' : 'default',
                        'shift' => $shift ? $this->shiftRow($shift) : null,
                        'is_day_off' => $isDayOff,
                        'day_off_reason' => $isDayOff ? ($dayOff['reason'] ?? 'Hari libur') : null,
                    ],
                    'assigned_at' => $pegawai->shift_assigned_at?->format('d M Y, H:i'),
                    'assigned_by' => $pegawai->shiftAssignedBy?->name,
                ];
            });
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

    /** @return array<int, array{id: int, nama: string, is_day_off: bool, jam_masuk: string, jam_keluar: string}> */
    public function shiftOptions(): array
    {
        return Shift::query()
            ->orderBy('jam_masuk')
            ->orderBy('nama')
            ->get(['id', 'nama', 'is_day_off', 'jam_masuk', 'jam_keluar'])
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

    /** @return array{id: int, nama: string, is_day_off: bool, jam_masuk: string, jam_keluar: string} */
    private function shiftRow(Shift $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'nama' => $shift->nama,
            'is_day_off' => $shift->is_day_off,
            'jam_masuk' => substr((string) $shift->jam_masuk, 0, 5),
            'jam_keluar' => substr((string) $shift->jam_keluar, 0, 5),
        ];
    }
}
