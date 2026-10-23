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
    /** Keep an approved arrival after midnight attached to the date its night shift began. */
    public function clockInRecord(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()->with('shift')->whereDate('tanggal_presensi', now()->toDateString())->first();
        if ($today || ! $pegawai->shift || $pegawai->shift->jam_keluar > $pegawai->shift->jam_masuk) {
            return $today;
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
        $record->setRelation('shift', $record->shift ?? $pegawai->shift);

        return $record;
    }

    /** Resolve the employee's schedule for one shift date, including approved overtime usage. */
    public function resolve(?int $pegawaiId, ?Shift $shift, CarbonInterface $date, ?Collection $approvedUsage = null): array
    {
        if (! $shift) {
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
