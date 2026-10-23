<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Models\HariLibur;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceLogController extends Controller
{
    public function __construct(private readonly AttendanceScheduleService $schedules) {}

    public function __invoke(Request $request, ShiftHolidayService $holidays): Response
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2100-12']]);
        $start = Carbon::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));
        $end = $start->copy()->endOfMonth();
        $pegawai = $request->user()->pegawai()->with('shift')->first();
        $records = $pegawai?->presensis()->with(['shift', 'clockInLocation:id,name', 'clockOutLocation:id,name'])
            ->whereBetween('tanggal_presensi', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn (Presensi $record) => $record->tanggal_presensi->toDateString()) ?? collect();
        $leaves = $pegawai ? CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('status', 'approved')
            ->whereDate('tanggal_mulai', '<=', $end)->whereDate('tanggal_selesai', '>=', $start)->get() : collect();
        $usages = $pegawai ? LemburRequest::query()->where('pegawai_id', $pegawai->id)->where('request_kind', 'usage')->where('status', 'approved')
            ->whereBetween('tanggal_lembur', [$start->toDateString(), $end->toDateString()])->get()
            ->groupBy(fn ($usage) => $usage->tanggal_lembur->toDateString()) : collect();
        foreach ($records as $date => $record) {
            $record->setRelation('approvedLemburUsage', $usages->get($date, collect()));
        }
        $calendar = $pegawai ? HariLibur::query()
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')->get()->groupBy(fn (HariLibur $holiday) => $holiday->tanggal->toDateString()) : collect();
        $days = [];

        if ($pegawai) {
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $record = $records->get($date->toDateString());
                $leave = $leaves->first(fn (CutiRequest $leave) => $date->betweenIncluded($leave->tanggal_mulai, $leave->tanggal_selesai));
                $days[] = $this->day($pegawai, $record, $date, $leave, $holidays,
                    $calendar->get($date->toDateString(), collect()), $usages->get($date->toDateString(), collect()));
            }
        }

        $summary = array_fill_keys(['late', 'early_departure', 'absent', 'no_clock_in', 'no_clock_out', 'day_off', 'on_time', 'leave', 'invalid', 'upcoming'], 0);
        foreach ($days as $day) {
            foreach ($summary as $key => $count) {
                $summary[$key] += (int) $day['flags'][$key];
            }
        }

        return Inertia::render('absensi/absensi-mobile/AttendanceLog', [
            'month' => $start->format('Y-m'),
            'monthLabel' => $start->locale('id')->translatedFormat('F Y'),
            'periodLabel' => $start->locale('id')->translatedFormat('d M Y').' – '.$end->locale('id')->translatedFormat('d M Y'),
            'today' => now()->toDateString(),
            'summary' => $summary,
            'days' => $days,
            'unavailableReason' => $pegawai ? null : 'Akun Anda belum terhubung dengan data pegawai. Hubungi administrator untuk melihat log absensi.',
        ]);
    }

    /**
     * @param  Collection<int, HariLibur>  $calendar
     * @return array<string, mixed>
     */
    private function day(Pegawai $pegawai, ?Presensi $record, Carbon $date, ?CutiRequest $leave, ShiftHolidayService $holidays, Collection $calendar, Collection $usages): array
    {
        $employed = $date->gte(($pegawai->tanggal_mulai_bekerja ?? $pegawai->created_at)->copy()->startOfDay());
        $assigned = ! $pegawai->shift_assigned_at || $date->gte($pegawai->shift_assigned_at->copy()->startOfDay());
        // Recorded shifts remain authoritative when the employee's current shift changes.
        $shift = $record ? $record->shift : ($employed && $assigned ? $pegawai->shift : null);
        $clockIn = $record?->clock_in_at;
        $clockOut = $record?->clock_out_at;
        $hasClock = $clockIn || $clockOut;
        $onLeave = $leave !== null && ! $hasClock;
        $dayOff = ! $onLeave && ! $hasClock ? $holidays->dayOffDetails($shift, $date, $calendar) : null;
        if (! $onLeave && $record?->is_day_off) {
            $dayOff ??= ['type' => 'day_off', 'label' => 'Libur', 'reason' => 'Hari libur'];
        }
        $dayOffReason = $dayOff['reason'] ?? null;
        $workday = $shift !== null && ! $dayOffReason && ! $onLeave;
        $future = $date->isAfter(now()->startOfDay());
        $schedule = $this->schedules->resolve($pegawai->id, $workday ? $shift : null, $date, $usages);
        $scheduledEnd = $schedule['end_at'];
        $closed = $scheduledEnd?->lte(now()) ?? false;
        $invalid = $clockIn && $clockOut && $clockOut->lt($clockIn);
        $late = $workday && $clockIn ? $record->lateMinutes() : 0;
        $early = $workday && $clockOut ? $record->earlyDepartureMinutes() : 0;
        $lateAllowance = $schedule['late_arrival_minutes'];
        $earlyAllowance = $schedule['early_departure_minutes'];
        $flags = [
            'late' => ! $invalid && $late > 0,
            'early_departure' => ! $invalid && $early > 0,
            'absent' => $workday && $closed && ! $hasClock,
            'no_clock_in' => $workday && ! $clockIn && $clockOut !== null,
            'no_clock_out' => $workday && $closed && $clockIn !== null && ! $clockOut,
            'day_off' => $dayOffReason !== null,
            'on_time' => ! $invalid && $workday && $clockIn !== null && $late === 0,
            'leave' => $onLeave,
            'invalid' => (bool) $invalid,
            'upcoming' => $workday && $future && ! $hasClock,
        ];
        [$status, $label, $tone] = match (true) {
            $flags['invalid'] => ['invalid', 'Tidak valid', 'danger'],
            $flags['day_off'] => ['day_off', $dayOff['label'], $dayOff['type'] === HariLibur::NASIONAL ? 'holiday' : 'rest'],
            $flags['leave'] => ['leave', 'Cuti', 'leave'],
            $flags['no_clock_in'] => ['no_clock_in', 'Tidak clock in', 'warning'],
            $flags['no_clock_out'] => ['no_clock_out', 'Tidak clock out', 'warning'],
            $flags['absent'] => ['absent', 'Absen', 'danger'],
            $flags['late'] => ['late', 'Terlambat', 'warning'],
            $flags['early_departure'] => ['early_departure', 'Pulang lebih awal', 'warning'],
            $clockIn && ! $clockOut => ['working', 'Sedang bekerja', 'success'],
            (bool) $hasClock => ['present', $workday ? 'Tepat waktu' : 'Tercatat', 'success'],
            $flags['upcoming'] => ['upcoming', 'Akan datang', 'muted'],
            ! $workday => ['unscheduled', 'Tanpa jadwal', 'muted'],
            default => ['pending', 'Belum clock in', 'muted'],
        };
        $duration = $clockIn && $clockOut && ! $invalid ? (int) $clockIn->diffInMinutes($clockOut) : null;

        return [
            'date' => $date->toDateString(),
            'date_label' => $date->copy()->locale('id')->translatedFormat('D, d M'),
            'full_date_label' => $date->copy()->locale('id')->translatedFormat('l, d F Y'),
            'day_number' => $date->format('d'),
            'day_name' => $date->copy()->locale('id')->translatedFormat('D'),
            'shift_name' => $dayOffReason ?? ($onLeave ? ($leave->nama_cuti ?: 'Cuti disetujui') : $shift?->nama),
            'schedule' => $shift && $workday ? $schedule['start_time'].' – '.$schedule['end_time'] : null,
            'status' => $status,
            'status_label' => $label,
            'tone' => $tone,
            'day_off_type' => $dayOff['type'] ?? null,
            'day_off_reason' => $dayOffReason,
            'clock_in' => $clockIn?->format('H:i'),
            'clock_out' => $clockOut?->format('H:i'),
            'clock_in_date_label' => $clockIn?->copy()->locale('id')->translatedFormat('d M Y, H:i'),
            'clock_out_date_label' => $clockOut?->copy()->locale('id')->translatedFormat('d M Y, H:i'),
            'clock_in_location' => $record?->clockInLocation?->name,
            'clock_out_location' => $record?->clockOutLocation?->name,
            'clock_in_address' => $record?->clock_in_address,
            'clock_out_address' => $record?->clock_out_address,
            'late_minutes' => $late,
            'early_departure_minutes' => $early,
            'lembur_late_allowance' => $lateAllowance,
            'lembur_early_allowance' => $earlyAllowance,
            'duration' => $duration !== null ? intdiv($duration, 60).' jam '.($duration % 60).' menit' : null,
            'notes' => $record?->notes ?? $dayOffReason,
            'flags' => $flags,
        ];
    }
}
