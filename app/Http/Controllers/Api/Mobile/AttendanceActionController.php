<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockInRequest;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockOutRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AbsensiMobile\AttendanceLocationVerifier;
use App\Services\Absensi\AbsensiMobile\AttendanceSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceActionController extends Controller
{
    public function __construct(
        private readonly AttendanceLocationVerifier $locationVerifier,
        private readonly AttendanceSubmissionService $submissionService,
    ) {}

    public function show(Request $request, string $action): JsonResponse
    {
        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            $this->fail('Profil pegawai belum terhubung. Hubungi administrator.');
        }

        $attendance = $action === 'clock-in'
            ? $pegawai->presensis()->whereDate('tanggal_presensi', now()->toDateString())->first()
            : $this->attendanceForClockOut($pegawai);

        if ($attendance?->is_day_off) {
            $this->fail('Hari ini tercatat sebagai hari libur. Presensi tidak diperlukan.');
        }
        if ($action === 'clock-in' && $attendance?->clock_in_at) {
            $this->fail('Clock-in hari ini sudah tercatat.');
        }
        if ($action === 'clock-out' && ! $attendance?->clock_in_at) {
            $this->fail('Lakukan clock-in terlebih dahulu sebelum clock-out.');
        }
        if ($action === 'clock-out' && $attendance?->clock_out_at) {
            $this->fail('Clock-out sudah tercatat.');
        }
        if (! $this->profilePhotoPath($request)) {
            $this->fail('Tambahkan foto profil wajah melalui menu Akun sebelum melakukan presensi.', 'photo');
        }

        $office = OfficeLocation::query()->oldest('id')->first();
        if (! $office) {
            $this->fail('Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
        }
        $shift = $attendance?->shift ?? $pegawai->shift;

        return response()->json([
            'data' => [
                'action' => $action,
                'schedule' => [
                    'date' => $attendance?->tanggal_presensi?->toDateString() ?? now()->toDateString(),
                    'date_label' => ($attendance?->tanggal_presensi ?? now())->locale('id')->translatedFormat('l, d F Y'),
                    'shift_name' => $shift?->nama,
                    'start_time' => $shift ? substr((string) $shift->jam_masuk, 0, 5) : null,
                    'end_time' => $shift ? substr((string) $shift->jam_keluar, 0, 5) : null,
                ],
                'office' => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'address' => $office->address,
                    'latitude' => (float) $office->latitude,
                    'longitude' => (float) $office->longitude,
                    'radius_meters' => (float) $office->radius_meters,
                ],
                'location_challenge' => $this->locationVerifier->issueChallenge($request, $action),
            ],
        ]);
    }

    public function clockIn(StoreClockInRequest $request): JsonResponse
    {
        $result = $this->submissionService->clockIn($request);

        return response()->json([
            'message' => $result['message'],
            'data' => $this->successPayload($result['attendance'], 'clock-in', $result['outside_radius']),
        ], 201);
    }

    public function clockOut(StoreClockOutRequest $request): JsonResponse
    {
        $result = $this->submissionService->clockOut($request);

        return response()->json([
            'message' => $result['message'],
            'data' => $this->successPayload($result['attendance'], 'clock-out', $result['outside_radius']),
        ]);
    }

    /** @return array<string, mixed> */
    private function successPayload(Presensi $attendance, string $action, bool $outsideRadius): array
    {
        $attendance->loadMissing('shift:id,nama,jam_masuk,jam_keluar');
        $isClockIn = $action === 'clock-in';
        $timestamp = $isClockIn ? $attendance->clock_in_at : $attendance->clock_out_at;

        return [
            'action' => $action,
            'date' => $attendance->tanggal_presensi?->toDateString(),
            'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
            'time' => $timestamp?->format('H:i'),
            'shift_name' => $attendance->shift?->nama,
            'outside_radius' => $outsideRadius,
            'face_verified' => $isClockIn
                ? $attendance->face_verified_at !== null
                : $attendance->clock_out_face_verified_at !== null,
            'face_similarity' => $isClockIn
                ? $attendance->face_similarity
                : $attendance->clock_out_face_similarity,
        ];
    }

    private function pegawai(Request $request): ?Pegawai
    {
        return $request->user()?->pegawai()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->first(['id', 'user_id', 'shift_id']);
    }

    private function attendanceForClockOut(Pegawai $pegawai): ?Presensi
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

        return $previous?->shift && (string) $previous->shift->jam_keluar <= (string) $previous->shift->jam_masuk
            ? $previous
            : null;
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

    private function fail(string $message, string $field = 'attendance'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
