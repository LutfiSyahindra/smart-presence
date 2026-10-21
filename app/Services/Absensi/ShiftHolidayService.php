<?php

namespace App\Services\Absensi;

use App\Models\HariLibur;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ShiftHolidayService
{
    public const BLOCKED_MESSAGE = 'Hari ini merupakan hari libur shift Anda. Presensi tidak diperlukan.';

    public function reason(?Shift $shift, CarbonInterface|string $date): ?string
    {
        if (! $shift) {
            return null;
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        if ($date->isSaturday() && $shift->libur_sabtu) {
            return 'Libur Sabtu';
        }
        if ($date->isSunday() && $shift->libur_minggu) {
            return 'Libur Minggu';
        }

        $types = array_filter([
            $shift->libur_nasional ? HariLibur::NASIONAL : null,
            $shift->libur_cuti_bersama ? HariLibur::CUTI_BERSAMA : null,
        ]);
        if (! $types) {
            return null;
        }

        return HariLibur::query()
            ->whereDate('tanggal', $date->toDateString())
            ->whereIn('jenis', $types)
            ->orderBy('id')
            ->value('nama');
    }

    public function dayOffReason(?Pegawai $pegawai, ?Presensi $attendance = null): ?string
    {
        if ($attendance?->is_day_off) {
            return 'Hari libur';
        }

        // Keep a started attendance available for clock-out, including overnight shifts.
        if ($attendance?->clock_in_at) {
            return null;
        }

        return $this->reason($attendance?->shift ?? $pegawai?->shift, $attendance?->tanggal_presensi ?? now());
    }

    public function isDayOff(?Pegawai $pegawai, ?Presensi $attendance = null): bool
    {
        return $this->dayOffReason($pegawai, $attendance) !== null;
    }

    public function ensureCanAttend(Pegawai $pegawai, ?Presensi $attendance = null): void
    {
        if ($this->isDayOff($pegawai, $attendance)) {
            throw ValidationException::withMessages(['attendance' => self::BLOCKED_MESSAGE]);
        }
    }
}
