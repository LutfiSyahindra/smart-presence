<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\CutiScheduleService;
use App\Support\AccessPermission;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(private readonly CutiScheduleService $cutiSchedule) {}

    public function __invoke(Request $request): Response
    {
        $pegawai = $request->user()?->pegawai()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->first(['id', 'user_id', 'shift_id']);
        $attendance = $pegawai ? $this->attendanceForToday($pegawai) : null;

        $attendance?->loadMissing([
            'shift:id,nama,jam_masuk,jam_keluar',
            'clockInLocation:id,name,address,latitude,longitude,radius_meters',
            'clockOutLocation:id,name,address,latitude,longitude,radius_meters',
        ]);

        $isOnCuti = $this->cutiSchedule->isOnCuti($pegawai, $attendance);
        $shift = $isOnCuti ? null : ($attendance?->shift ?? $pegawai?->shift);
        $office = OfficeLocation::query()->oldest('id')->first(['id', 'name', 'address']);
        $attendanceDate = $isOnCuti ? now() : ($attendance?->tanggal_presensi ?? now());
        $clockIn = $attendance?->clock_in_at;
        $clockOut = $attendance?->clock_out_at;

        return Inertia::render('absensi/absensi-mobile/Attendance', [
            'canAccessAccount' => $request->user()?->can(AccessPermission::MOBILE_ACCOUNT) ?? false,
            'today' => [
                'date' => $attendanceDate->toDateString(),
                'date_label' => $attendanceDate->locale('id')->translatedFormat('l, d F Y'),
                'shift_name' => $isOnCuti ? 'CUTI' : $shift?->nama,
                'start_time' => $shift ? substr((string) $shift->jam_masuk, 0, 5) : null,
                'end_time' => $shift ? substr((string) $shift->jam_keluar, 0, 5) : null,
                'location_name' => $office?->name,
                'location_address' => $office?->address,
            ],
            'attendance' => [
                'is_day_off' => $attendance?->is_day_off ?? false,
                'is_on_leave' => $isOnCuti,
                'status' => $isOnCuti ? 'on_leave' : $this->status($pegawai, $attendance, $office),
                'duration' => $this->durationLabel($clockIn, $clockOut),
                'notes' => $attendance?->notes,
                'clock_in' => $clockIn ? [
                    'time' => $clockIn->format('H:i'),
                    'date_time_label' => $clockIn->locale('id')->translatedFormat('d M Y, H:i').' WIB',
                    'location_name' => $attendance?->clockInLocation?->name,
                    'address' => $attendance?->clock_in_address,
                    'outside_radius' => $this->isOutsideRadius(
                        $attendance?->clock_in_latitude,
                        $attendance?->clock_in_longitude,
                        $attendance?->clockInLocation,
                    ),
                    'face_verified' => $attendance?->face_verified_at !== null,
                    'face_similarity' => $attendance?->face_similarity,
                    'schedule_status' => $this->clockInStatus($attendance),
                    'schedule_status_tone' => ($attendance?->lateMinutes() ?? 0) > 0 ? 'warning' : 'success',
                ] : null,
                'clock_out' => $clockOut ? [
                    'time' => $clockOut->format('H:i'),
                    'date_time_label' => $clockOut->locale('id')->translatedFormat('d M Y, H:i').' WIB',
                    'location_name' => $attendance?->clockOutLocation?->name,
                    'address' => $attendance?->clock_out_address,
                    'outside_radius' => $this->isOutsideRadius(
                        $attendance?->clock_out_latitude,
                        $attendance?->clock_out_longitude,
                        $attendance?->clockOutLocation,
                    ),
                    'face_verified' => $attendance?->clock_out_face_verified_at !== null,
                    'face_similarity' => $attendance?->clock_out_face_similarity,
                    'schedule_status' => $this->clockOutStatus($attendance),
                    'schedule_status_tone' => ($attendance?->earlyDepartureMinutes() ?? 0) > 0 ? 'warning' : 'success',
                ] : null,
            ],
        ]);
    }

    private function attendanceForToday(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->whereDate('tanggal_presensi', now()->toDateString())
            ->first();

        if ($today) {
            return $today;
        }

        $previous = $pegawai->presensis()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->whereDate('tanggal_presensi', now()->subDay()->toDateString())
            ->whereNotNull('clock_in_at')
            ->latest('clock_in_at')
            ->first();

        if (! $previous?->shift || (string) $previous->shift->jam_keluar > (string) $previous->shift->jam_masuk
            || $this->cutiSchedule->isOnCuti($pegawai, $previous)) {
            return null;
        }

        $scheduledEnd = Carbon::createFromFormat(
            'Y-m-d H:i',
            $previous->tanggal_presensi->format('Y-m-d').' '.substr((string) $previous->shift->jam_keluar, 0, 5),
        )->addDay();

        return ! $previous->clock_out_at || now()->lte($scheduledEnd->copy()->addHours(4))
            ? $previous
            : null;
    }

    private function status(?Pegawai $pegawai, ?Presensi $attendance, ?OfficeLocation $office): string
    {
        if ($attendance?->is_day_off) {
            return 'day_off';
        }
        if ($attendance?->clock_out_at) {
            return 'complete';
        }
        if (! $pegawai || ! ($attendance?->shift ?? $pegawai->shift) || ! $office) {
            return 'unavailable';
        }
        if (! $attendance?->clock_in_at) {
            return 'ready_to_clock_in';
        }

        return 'ready_to_clock_out';
    }

    private function durationLabel(?Carbon $clockIn, ?Carbon $clockOut): ?string
    {
        if (! $clockIn) {
            return null;
        }

        $end = $clockOut ?? now();
        if ($end->lt($clockIn)) {
            return null;
        }

        $minutes = (int) $clockIn->diffInMinutes($end);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours > 0
            ? $hours.' jam '.$remainingMinutes.' menit'
            : $remainingMinutes.' menit';
    }

    private function clockInStatus(?Presensi $attendance): string
    {
        $lateMinutes = $attendance?->lateMinutes() ?? 0;

        return $lateMinutes > 0
            ? 'Terlambat '.$lateMinutes.' menit'
            : 'Tepat waktu';
    }

    private function clockOutStatus(?Presensi $attendance): string
    {
        $earlyMinutes = $attendance?->earlyDepartureMinutes() ?? 0;

        return $earlyMinutes > 0
            ? 'Lebih awal '.$earlyMinutes.' menit'
            : 'Sesuai jadwal';
    }

    private function isOutsideRadius(?float $latitude, ?float $longitude, ?OfficeLocation $location): bool
    {
        if ($latitude === null || $longitude === null || ! $location) {
            return false;
        }

        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad((float) $location->latitude - $latitude);
        $longitudeDelta = deg2rad((float) $location->longitude - $longitude);
        $originLatitude = deg2rad($latitude);
        $destinationLatitude = deg2rad((float) $location->latitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($originLatitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;
        $a = min(1, max(0, $a));
        $distance = $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));

        return $distance > $location->radius_meters;
    }
}
