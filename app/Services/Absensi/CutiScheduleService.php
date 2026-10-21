<?php

namespace App\Services\Absensi;

use App\Models\CutiRequest;
use App\Models\Pegawai;
use App\Models\Presensi;
use Illuminate\Validation\ValidationException;

class CutiScheduleService
{
    public const BLOCKED_MESSAGE = 'Cuti Anda telah disetujui. Clock-in dan clock-out tidak dapat dilakukan selama cuti.';

    public function isOnCuti(?Pegawai $pegawai, ?Presensi $attendance = null): bool
    {
        if (! $pegawai) {
            return false;
        }

        $dates = array_unique(array_filter([
            now()->toDateString(),
            $attendance?->tanggal_presensi?->toDateString(),
        ]));

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

    public function ensureCanAttend(Pegawai $pegawai, ?Presensi $attendance = null): void
    {
        if ($this->isOnCuti($pegawai, $attendance)) {
            throw ValidationException::withMessages(['attendance' => self::BLOCKED_MESSAGE]);
        }
    }
}
