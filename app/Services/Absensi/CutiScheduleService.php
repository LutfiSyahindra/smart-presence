<?php

namespace App\Services\Absensi;

use App\Models\CutiRequest;
use App\Models\Pegawai;
use App\Models\Presensi;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class CutiScheduleService
{
    public const BLOCKED_MESSAGE = 'Cuti Anda telah disetujui. Clock-in dan clock-out tidak dapat dilakukan selama cuti.';

    public function isOnCuti(?Pegawai $pegawai, ?Presensi $attendance = null, ?CarbonInterface $at = null): bool
    {
        if (! $pegawai) {
            return false;
        }

        $at ??= now();
        $dates = array_unique(array_filter([
            $at->toDateString(),
            $attendance?->tanggal_presensi?->toDateString(),
        ]));

        // Finish yesterday's started night shift using its attendance date, even if leave starts today.
        // Use the received time for queued submissions, which may be processed on a later day.
        if ($attendance?->clock_in_at && ! $attendance->clock_out_at && ! $attendance->is_day_off
            && $attendance->tanggal_presensi?->isSameDay($at->copy()->subDay())
            && $attendance->shift && ! $attendance->shift->is_day_off
            && $attendance->shift->jam_keluar <= $attendance->shift->jam_masuk) {
            $dates = [$attendance->tanggal_presensi->toDateString()];
        }

        return CutiRequest::query()
            ->where('pegawai_id', $pegawai->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($dates) {
                foreach ($dates as $date) {
                    $query->orWhere(fn ($query) => $query
                        ->whereDate('tanggal_mulai', '<=', $date)
                        ->whereDate('tanggal_selesai', '>=', $date));
                }
            })
            ->exists();
    }

    public function ensureCanAttend(Pegawai $pegawai, ?Presensi $attendance = null, ?CarbonInterface $at = null): void
    {
        if ($this->isOnCuti($pegawai, $attendance, $at)) {
            throw ValidationException::withMessages(['attendance' => self::BLOCKED_MESSAGE]);
        }
    }
}
