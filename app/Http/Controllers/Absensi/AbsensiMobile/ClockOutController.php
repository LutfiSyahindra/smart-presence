<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockOutRequest;
use App\Models\AttendanceSubmission;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AbsensiMobile\AttendanceLocationVerifier;
use App\Services\Absensi\AbsensiMobile\AttendanceSubmissionService;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\CutiScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ClockOutController extends Controller
{
    public function __construct(
        private readonly AttendanceLocationVerifier $locationVerifier,
        private readonly AttendanceSubmissionService $submissionService,
        private readonly CutiScheduleService $cutiSchedule,
        private readonly ShiftHolidayService $shiftHolidays,
        private readonly AttendanceScheduleService $schedules,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($pending = AttendanceSubmission::query()->where('user_id', $request->user()->id)->where('action', 'clock-out')
            ->whereIn('status', ['pending', 'processing'])->first()) {
            return to_route('absensi.mobile.attendance-submissions.show', $pending->id);
        }

        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            return to_route('absensi.mobile.home')->with('error', 'Profil pegawai belum terhubung. Hubungi administrator sebelum melakukan presensi.');
        }

        $attendance = $this->attendanceForClockOut($pegawai);
        if ($this->cutiSchedule->isOnCuti($pegawai, $attendance)) {
            return to_route('absensi.mobile.home')->with('error', CutiScheduleService::BLOCKED_MESSAGE);
        }
        if ($this->shiftHolidays->isDayOff($pegawai, $attendance)) {
            return to_route('absensi.mobile.home')->with('error', 'Hari ini tercatat sebagai hari libur. Clock-out tidak diperlukan.');
        }
        if (! $attendance?->clock_in_at) {
            return to_route('absensi.mobile.home')->with('error', 'Lakukan clock-in terlebih dahulu sebelum clock-out.');
        }
        if ($attendance->clock_out_at) {
            return to_route('absensi.mobile.home')
                ->with('success', 'Anda sudah clock-out pada '.$attendance->clock_out_at->format('H:i').' WIB.');
        }

        $office = $this->office();
        if (! $office) {
            return to_route('absensi.mobile.home')->with('error', 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
        }

        if (! $this->profilePhotoPath($request)) {
            $message = 'Tambahkan foto profil wajah terlebih dahulu sebelum melakukan clock-out.';

            return $request->user()->can(AccessPermission::MOBILE_ACCOUNT)
                ? to_route('absensi.mobile.account.show')->with('error', $message)
                : to_route('absensi.mobile.home')->with('error', $message);
        }

        $attendance->loadMissing('shift');

        $schedule = $this->schedules->resolve($pegawai->id, $attendance->shift, $attendance->tanggal_presensi);

        return Inertia::render('absensi/absensi-mobile/ClockOut', [
            'schedule' => [
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
                'shift_name' => $attendance->shift?->nama,
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'schedule_note' => $schedule['note'],
            ],
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'address' => $office->address,
                'latitude' => $office->latitude,
                'longitude' => $office->longitude,
                'radius_meters' => $office->radius_meters,
            ],
            'locationChallenge' => fn () => $this->locationVerifier->issueChallenge($request, 'clock-out'),
            'profilePhotoUrl' => $this->profilePhotoUrl($request),
        ]);
    }

    public function success(Request $request): Response|RedirectResponse
    {
        $pegawai = $this->pegawai($request);
        $attendance = $pegawai ? $this->attendanceForClockOut($pegawai) : null;

        if (! $attendance?->clock_out_at) {
            return to_route('absensi.mobile.home')->with('error', 'Data clock-out terakhir belum ditemukan.');
        }

        $attendance->loadMissing([
            'shift',
            'clockOutLocation:id,name,address,latitude,longitude,radius_meters',
        ]);
        $location = $attendance->clockOutLocation;
        $outsideRadius = $location
            && $attendance->clock_out_latitude !== null
            && $attendance->clock_out_longitude !== null
            && $this->distanceInMeters(
                (float) $attendance->clock_out_latitude,
                (float) $attendance->clock_out_longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            ) > $location->radius_meters;

        $schedule = $this->schedules->resolve($pegawai->id, $attendance->shift, $attendance->tanggal_presensi);

        return Inertia::render('absensi/absensi-mobile/ClockOutSuccess', [
            'attendance' => [
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
                'clock_out_time' => $attendance->clock_out_at->format('H:i'),
                'early_departure_minutes' => $attendance->earlyDepartureMinutes(),
                'shift_name' => $attendance->shift?->nama,
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'schedule_note' => $schedule['note'],
                'location_name' => $location?->name,
                'location_address' => $attendance->clock_out_address,
                'outside_radius' => (bool) $outsideRadius,
                'face_verified' => $attendance->clock_out_face_verified_at !== null,
                'face_similarity' => $attendance->clock_out_face_similarity,
            ],
        ]);
    }

    public function store(StoreClockOutRequest $request): RedirectResponse
    {
        $result = $this->submissionService->clockOut($request);

        if (isset($result['submission'])) {
            return to_route('absensi.mobile.attendance-submissions.show', $result['submission']->id);
        }

        return to_route('absensi.mobile.clock-out.success')->with('success', $result['message']);
    }

    private function pegawai(Request $request): ?Pegawai
    {
        return $request->user()?->pegawai()
            ->with('shift')
            ->first(['id', 'user_id', 'shift_id']);
    }

    private function office(): ?OfficeLocation
    {
        return OfficeLocation::query()->oldest('id')->first();
    }

    private function attendanceForClockOut(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()
            ->with('shift')
            ->whereDate('tanggal_presensi', now()->toDateString())
            ->first();
        if ($today) {
            return $today;
        }

        $previous = $pegawai->presensis()
            ->with('shift')
            ->whereDate('tanggal_presensi', now()->subDay()->toDateString())
            ->whereNotNull('clock_in_at')
            ->latest('clock_in_at')
            ->first();

        return $previous?->shift && (string) $previous->shift->jam_keluar <= (string) $previous->shift->jam_masuk
            ? $previous
            : null;
    }

    private function profilePhotoUrl(Request $request): ?string
    {
        $user = $request->user();
        $path = $this->profilePhotoPath($request);

        if (! $user || ! $user->can(AccessPermission::MOBILE_ACCOUNT) || ! $path) {
            return null;
        }

        return route('absensi.mobile.account.photo.show', absolute: false).'?v='.$user->profile_photo_updated_at?->timestamp;
    }

    private function profilePhotoPath(Request $request): ?string
    {
        $user = $request->user();
        $path = $user?->profile_photo_path;

        if (! $user || ! $path || ! Str::startsWith($path, "profile-photos/{$user->id}/") || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $path;
    }

    private function distanceInMeters(float $latitude, float $longitude, float $officeLatitude, float $officeLongitude): float
    {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($officeLatitude - $latitude);
        $longitudeDelta = deg2rad($officeLongitude - $longitude);
        $originLatitude = deg2rad($latitude);
        $destinationLatitude = deg2rad($officeLatitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($originLatitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;
        $a = min(1, max(0, $a));

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
