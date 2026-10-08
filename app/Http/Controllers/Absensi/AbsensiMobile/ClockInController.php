<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockInRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AbsensiMobile\AttendanceLocationVerifier;
use App\Services\Absensi\AbsensiMobile\AttendanceSubmissionService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ClockInController extends Controller
{
    public function __construct(
        private readonly AttendanceLocationVerifier $locationVerifier,
        private readonly AttendanceSubmissionService $submissionService,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            return to_route('absensi.mobile.home')->with('error', 'Profil pegawai belum terhubung. Hubungi administrator sebelum melakukan presensi.');
        }

        $existing = $this->todayPresensi($pegawai);
        if ($existing?->is_day_off) {
            return to_route('absensi.mobile.home')->with('error', 'Hari ini tercatat sebagai hari libur. Clock-in tidak diperlukan.');
        }
        if ($existing?->clock_in_at) {
            return to_route('absensi.mobile.home')
                ->with('success', 'Anda sudah clock-in hari ini pada '.$existing->clock_in_at->format('H:i').' WIB.');
        }

        $office = $this->office();
        if (! $office) {
            return to_route('absensi.mobile.home')->with('error', 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
        }

        if (! $this->profilePhotoPath($request)) {
            $message = 'Tambahkan foto profil wajah terlebih dahulu sebelum melakukan clock-in.';

            return $request->user()->can(AccessPermission::MOBILE_ACCOUNT)
                ? to_route('absensi.mobile.account.show')->with('error', $message)
                : to_route('absensi.mobile.home')->with('error', $message);
        }

        return Inertia::render('absensi/absensi-mobile/ClockIn', [
            'schedule' => [
                'date' => now()->toDateString(),
                'date_label' => now()->locale('id')->translatedFormat('l, d F Y'),
                'shift_name' => $pegawai->shift?->nama,
                'start_time' => $pegawai->shift ? substr((string) $pegawai->shift->jam_masuk, 0, 5) : null,
                'end_time' => $pegawai->shift ? substr((string) $pegawai->shift->jam_keluar, 0, 5) : null,
            ],
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'address' => $office->address,
                'latitude' => $office->latitude,
                'longitude' => $office->longitude,
                'radius_meters' => $office->radius_meters,
            ],
            'locationChallenge' => $this->locationVerifier->issueChallenge($request, 'clock-in'),
            'profilePhotoUrl' => $this->profilePhotoUrl($request),
        ]);
    }

    public function success(Request $request): Response|RedirectResponse
    {
        $pegawai = $this->pegawai($request);
        $attendance = $pegawai ? $this->todayPresensi($pegawai) : null;

        if (! $attendance?->clock_in_at) {
            return to_route('absensi.mobile.home')->with('error', 'Data clock-in hari ini belum ditemukan.');
        }

        $attendance->loadMissing([
            'shift:id,nama,jam_masuk,jam_keluar',
            'clockInLocation:id,name,address,latitude,longitude,radius_meters',
        ]);
        $location = $attendance->clockInLocation;
        $outsideRadius = $location
            && $attendance->clock_in_latitude !== null
            && $attendance->clock_in_longitude !== null
            && $this->distanceInMeters(
                (float) $attendance->clock_in_latitude,
                (float) $attendance->clock_in_longitude,
                (float) $location->latitude,
                (float) $location->longitude,
            ) > $location->radius_meters;

        return Inertia::render('absensi/absensi-mobile/ClockInSuccess', [
            'attendance' => [
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
                'clock_in_time' => $attendance->clock_in_at->format('H:i'),
                'late_minutes' => $attendance->lateMinutes(),
                'shift_name' => $attendance->shift?->nama,
                'start_time' => $attendance->shift ? substr((string) $attendance->shift->jam_masuk, 0, 5) : null,
                'end_time' => $attendance->shift ? substr((string) $attendance->shift->jam_keluar, 0, 5) : null,
                'location_name' => $location?->name,
                'location_address' => $attendance->clock_in_address,
                'outside_radius' => (bool) $outsideRadius,
                'face_verified' => $attendance->face_verified_at !== null,
                'face_similarity' => $attendance->face_similarity,
            ],
        ]);
    }

    public function store(StoreClockInRequest $request): RedirectResponse
    {
        $result = $this->submissionService->clockIn($request);

        return to_route('absensi.mobile.clock-in.success')->with('success', $result['message']);
    }

    private function pegawai(Request $request): ?Pegawai
    {
        return $request->user()?->pegawai()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->first(['id', 'user_id', 'shift_id']);
    }

    private function office(): ?OfficeLocation
    {
        return OfficeLocation::query()->oldest('id')->first();
    }

    private function todayPresensi(Pegawai $pegawai): ?Presensi
    {
        return $pegawai->presensis()->whereDate('tanggal_presensi', now()->toDateString())->first();
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
