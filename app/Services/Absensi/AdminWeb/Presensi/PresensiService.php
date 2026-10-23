<?php

namespace App\Services\Absensi\AdminWeb\Presensi;

use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Repositories\Absensi\AdminWeb\Presensi\PresensiRepositoryInterface;
use App\Services\Absensi\AttendanceScheduleService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class PresensiService
{
    public function __construct(
        private readonly PresensiRepositoryInterface $presensis,
        private readonly AttendanceScheduleService $schedules,
    ) {}

    /** @param array<string, mixed> $filters */
    public function normalizeFilters(array $filters): array
    {
        $today = now()->toDateString();
        $from = $this->validDate($filters['date_from'] ?? null) ?? $today;
        $to = $this->validDate($filters['date_to'] ?? null) ?? $from;

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $allowedStatuses = ['present', 'late', 'on-time', 'incomplete', 'overtime', 'day-off'];
        $status = in_array($filters['status'] ?? null, $allowedStatuses, true) ? $filters['status'] : null;

        return [
            'search' => trim((string) ($filters['search'] ?? '')) ?: null,
            'date_from' => $from,
            'date_to' => $to,
            'status' => $status,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $paginator = $this->presensis->paginate($filters);
        $recordIds = $paginator->getCollection()
            ->map(fn (Pegawai $pegawai) => $pegawai->getAttribute('presensi_record_id'))
            ->filter()
            ->values();
        $presensis = Presensi::query()
            ->with([
                'shift:id,nama,jam_masuk,jam_keluar',
                'clockInLocation:id,name,address,latitude,longitude',
                'clockOutLocation:id,name,address,latitude,longitude',
                'updatedBy:id,name',
            ])
            ->whereKey($recordIds)
            ->get()
            ->keyBy('id');

        return $paginator->through(fn (Pegawai $pegawai) => $this->row(
            $pegawai,
            $presensis->get($pegawai->getAttribute('presensi_record_id')),
            $filters['date_from'],
        ));
    }

    /** @param array<string, mixed> $filters */
    public function overview(array $filters): array
    {
        return $this->presensis->overview($filters);
    }

    /** @return array{pegawai: array<int, array<string, mixed>>, shifts: array<int, array<string, mixed>>} */
    public function formOptions(): array
    {
        return [
            'pegawai' => Pegawai::query()
                ->with(['unitKerja:id,nama', 'jabatan:id,nama'])
                ->whereHas('user')
                ->orderBy('nama')
                ->get(['id', 'user_id', 'shift_id', 'nik', 'nama', 'unit_kerja_id', 'jabatan_id'])
                ->map(fn (Pegawai $pegawai) => [
                    'id' => $pegawai->id,
                    'nik' => $pegawai->nik,
                    'nama' => $pegawai->nama,
                    'unit' => $pegawai->unitKerja?->nama,
                    'jabatan' => $pegawai->jabatan?->nama,
                    'shift_id' => $pegawai->shift_id,
                ])->all(),
            'shifts' => Shift::query()
                ->orderBy('jam_masuk')
                ->get(['id', 'nama', 'jam_masuk', 'jam_keluar'])
                ->map(fn (Shift $shift) => [
                    'id' => $shift->id,
                    'nama' => $shift->nama,
                    'jam_masuk' => substr((string) $shift->jam_masuk, 0, 5),
                    'jam_keluar' => substr((string) $shift->jam_keluar, 0, 5),
                ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $updatedBy): Presensi
    {
        return $this->presensis->create($this->attributes($data, $updatedBy));
    }

    /** @param array<string, mixed> $data */
    public function update(Presensi $presensi, array $data, int $updatedBy): Presensi
    {
        return $this->presensis->update($presensi, $this->attributes($data, $updatedBy));
    }

    /** @return array<string, mixed> */
    private function row(Pegawai $pegawai, ?Presensi $presensi, string $fallbackDate): array
    {
        $lateMinutes = $presensi ? $this->lateMinutes($presensi) : 0;
        $earlyDepartureMinutes = $presensi?->earlyDepartureMinutes() ?? 0;
        $date = $presensi?->tanggal_presensi?->format('Y-m-d') ?? $fallbackDate;
        $schedule = $this->schedules->resolve($pegawai->id, $presensi?->shift, $presensi?->tanggal_presensi ?? Carbon::parse($fallbackDate));

        return [
            'id' => $presensi?->id,
            'has_presensi' => $presensi !== null,
            'pegawai_id' => $pegawai->id,
            'pegawai' => [
                'nama' => $pegawai->nama,
                'nik' => $pegawai->nik,
                'unit' => $pegawai->unitKerja?->nama,
                'jabatan' => $pegawai->jabatan?->nama,
            ],
            'tanggal' => $date,
            'tanggal_label' => Carbon::createFromFormat('Y-m-d', $date)->format('d/m/Y'),
            'shift_id' => $presensi?->shift_id,
            'shift' => $presensi?->shift ? [
                'nama' => $presensi->shift->nama,
                'jam_masuk' => $schedule['start_time'],
                'jam_keluar' => $schedule['end_time'],
            ] : null,
            'clock_in' => [
                'time' => $presensi?->clock_in_at?->format('H:i'),
                'date_time' => $presensi?->clock_in_at?->format('Y-m-d H:i'),
                'location' => $presensi ? $this->location($presensi, 'in') : null,
                'photo_url' => $presensi ? $this->photoUrl($presensi, $presensi->clock_in_photo_path, 'in') : null,
            ],
            'clock_out' => [
                'time' => $presensi?->clock_out_at?->format('H:i'),
                'date_time' => $presensi?->clock_out_at?->format('Y-m-d H:i'),
                'location' => $presensi ? $this->location($presensi, 'out') : null,
                'photo_url' => $presensi ? $this->photoUrl($presensi, $presensi->clock_out_photo_path, 'out') : null,
            ],
            'is_day_off' => $presensi?->is_day_off ?? false,
            'overtime_minutes' => $presensi?->overtime_minutes ?? 0,
            'notes' => $presensi?->notes,
            'late_minutes' => $lateMinutes,
            'lembur_late_arrival' => $presensi && ! $presensi->is_day_off && $schedule['late_arrival_minutes'] > 0 ? [
                'minutes' => $schedule['late_arrival_minutes'],
                'original_time' => substr((string) $presensi->shift->jam_masuk, 0, 5),
                'adjusted_time' => $schedule['start_time'],
            ] : null,
            'early_departure_minutes' => $earlyDepartureMinutes,
            'status' => $presensi ? $this->status($presensi, $lateMinutes) : null,
            'updated_by' => $presensi?->updatedBy?->name,
            'updated_at' => $presensi?->updated_at?->format('d/m/Y H:i'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data, int $updatedBy): array
    {
        $dayOff = (bool) $data['is_day_off'];
        $clockIn = $dayOff ? null : $this->dateTime($data['tanggal_presensi'], $data['clock_in_time'] ?? null);
        $clockOut = $dayOff ? null : $this->dateTime($data['tanggal_presensi'], $data['clock_out_time'] ?? null);

        if ($clockOut && $clockIn && $clockOut->lt($clockIn)) {
            $clockOut->addDay();
        } elseif ($clockOut && ! $clockIn && isset($data['shift_id'])) {
            $shift = Shift::query()->find($data['shift_id']);
            if ($shift && (string) $shift->jam_keluar <= (string) $shift->jam_masuk) {
                $clockOut->addDay();
            }
        }

        return [
            'pegawai_id' => $data['pegawai_id'],
            'shift_id' => $data['shift_id'] ?? null,
            'tanggal_presensi' => $data['tanggal_presensi'],
            'clock_in_at' => $clockIn,
            'clock_out_at' => $clockOut,
            'is_day_off' => $dayOff,
            'overtime_minutes' => $dayOff ? 0 : (int) ($data['overtime_minutes'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'updated_by' => $updatedBy,
        ];
    }

    private function dateTime(string $date, ?string $time): ?Carbon
    {
        return $time ? Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}")->seconds(0) : null;
    }

    private function lateMinutes(Presensi $presensi): int
    {
        return $presensi->lateMinutes();
    }

    private function status(Presensi $presensi, int $lateMinutes): string
    {
        if ($presensi->is_day_off) {
            return 'day-off';
        }
        if (! $presensi->shift || ! $presensi->clock_in_at || ! $presensi->clock_out_at) {
            return 'incomplete';
        }

        return $lateMinutes > 0 ? 'late' : 'on-time';
    }

    /** @return array<string, mixed>|null */
    private function location(Presensi $presensi, string $type): ?array
    {
        $office = $type === 'in' ? $presensi->clockInLocation : $presensi->clockOutLocation;
        $latitude = $type === 'in' ? $presensi->clock_in_latitude : $presensi->clock_out_latitude;
        $longitude = $type === 'in' ? $presensi->clock_in_longitude : $presensi->clock_out_longitude;
        $address = $type === 'in' ? $presensi->clock_in_address : $presensi->clock_out_address;
        $latitude ??= $office?->latitude;
        $longitude ??= $office?->longitude;

        if (! $office && ! $address && $latitude === null && $longitude === null) {
            return null;
        }

        return [
            'name' => $office?->name,
            'address' => $address ?: $office?->address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'map_url' => $latitude !== null && $longitude !== null
                ? "https://www.google.com/maps?q={$latitude},{$longitude}"
                : null,
        ];
    }

    private function photoUrl(Presensi $presensi, ?string $path, string $type): ?string
    {
        if (! $path) {
            return null;
        }
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return route('absensi.admin-web.presensi.photo', ['presensi' => $presensi->id, 'type' => $type], false);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
