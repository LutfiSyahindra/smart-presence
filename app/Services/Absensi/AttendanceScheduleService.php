<?php

namespace App\Services\Absensi;

use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AttendanceScheduleService
{
    /** Keep an unfinished night shift active only until the next working shift starts. */
    public function clockInRecord(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()->with('shift')->whereDate('tanggal_presensi', now()->toDateString())->first();
        $current = $this->continuingOvernightRecord($pegawai, $today);
        if ($current && ! $current->tanggal_presensi->isSameDay(now()) && app(CutiScheduleService::class)->isOnCuti($pegawai, $current)) {
            $current = $today;
        }
        if ($current || $this->hasNextShiftStarted($pegawai, $today) || ! $pegawai->shift || $pegawai->shift->is_day_off || $pegawai->shift->jam_keluar > $pegawai->shift->jam_masuk) {
            return $current;
        }

        $date = now()->subDay()->startOfDay();
        $usages = LemburRequest::query()->where('pegawai_id', $pegawai->id)->where('shift_id', $pegawai->shift_id)
            ->where('request_kind', 'usage')->where('status', 'approved')->whereDate('tanggal_lembur', $date)->get();
        $crossesMidnight = $usages->contains(fn ($usage) => $usage->usage_type === 'late_arrival'
            && $usage->usage_start_at->lt(now()->startOfDay()) && $usage->usage_end_at->gte(now()->startOfDay()));
        if (! $crossesMidnight) {
            return null;
        }

        $schedule = $this->resolve($pegawai->id, $pegawai->shift, $date, $usages);
        if ($schedule['late_arrival_minutes'] === 0 || now()->gt($schedule['end_at'])) {
            return null;
        }

        $record = $pegawai->presensis()->with('shift')->whereDate('tanggal_presensi', $date)->first()
            ?? new Presensi(['pegawai_id' => $pegawai->id, 'shift_id' => $pegawai->shift_id, 'tanggal_presensi' => $date]);
        if ($record->clock_out_at) {
            return null;
        }
        $record->setRelation('shift', $record->shift ?? $pegawai->shift);

        return $record;
    }

    public function clockOutRecord(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()->with('shift')->whereDate('tanggal_presensi', now()->toDateString())->first();

        return $this->continuingOvernightRecord($pegawai, $today);
    }

    /** Yesterday's night shift stops accepting clock-out when the next working shift starts. */
    public function continuingOvernightRecord(Pegawai $pegawai, ?Presensi $today): ?Presensi
    {
        if ($today?->clock_in_at || $this->hasNextShiftStarted($pegawai, $today)) {
            return $today;
        }

        $previous = $pegawai->presensis()->with('shift')->whereDate('tanggal_presensi', now()->subDay()->toDateString())
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')->first();

        return $previous?->shift && ! $previous->shift->is_day_off && $previous->shift->jam_keluar <= $previous->shift->jam_masuk
            ? $previous : $today;
    }

    private function hasNextShiftStarted(Pegawai $pegawai, ?Presensi $today): bool
    {
        $shift = $today?->shift ?? $pegawai->shift;
        if (! $shift || ! $shift->jam_masuk || app(ShiftHolidayService::class)->isDayOff($pegawai, $today)) {
            return false;
        }

        return now()->gte(now()->startOfDay()->setTimeFromTimeString($shift->jam_masuk));
    }

    /** Resolve the employee's schedule for one shift date, including approved overtime usage. */
    public function resolve(?int $pegawaiId, ?Shift $shift, CarbonInterface $date, ?Collection $approvedUsage = null): array
    {
        if (! $shift || $shift->is_day_off) {
            return ['start_at' => null, 'end_at' => null, 'start_time' => null, 'end_time' => null,
                'late_arrival_minutes' => 0, 'early_departure_minutes' => 0, 'note' => null];
        }

        $start = Carbon::parse($date->toDateString().' '.substr((string) $shift->jam_masuk, 0, 5));
        $end = Carbon::parse($date->toDateString().' '.substr((string) $shift->jam_keluar, 0, 5));
        if ($end->lte($start)) {
            $end->addDay();
        }

        // Use the same approval, date, and shift checks as attendance timing, even before a clock-in exists.
        $record = new Presensi(['pegawai_id' => $pegawaiId, 'shift_id' => $shift->id, 'tanggal_presensi' => $date]);
        $record->setRelation('shift', $shift);
        $record->setRelation('approvedLemburUsage', $approvedUsage ?? $record->approvedLemburUsage());
        $late = $record->lemburAllowance('late_arrival', $start, $end);
        $early = $record->lemburAllowance('early_departure', $start, $end);
        $notes = [];
        if ($late > 0) {
            $notes[] = "telat masuk {$late} menit";
        }
        if ($early > 0) {
            $notes[] = "pulang cepat {$early} menit";
        }

        $start->addMinutes($late);
        $end->subMinutes($early);

        return ['start_at' => $start, 'end_at' => $end, 'start_time' => $start->format('H:i'), 'end_time' => $end->format('H:i'),
            'late_arrival_minutes' => $late, 'early_departure_minutes' => $early,
            'note' => $notes ? 'Penggunaan lembur disetujui: '.implode(', ', $notes).'.' : null];
    }
}
