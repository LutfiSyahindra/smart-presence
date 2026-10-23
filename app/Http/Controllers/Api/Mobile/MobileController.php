<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\UpdateProfilePhotoRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\CutiScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use App\Support\AccessPermission;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileController extends Controller
{
    public function __construct(
        private readonly CutiScheduleService $cutiSchedule,
        private readonly ShiftHolidayService $shiftHolidays,
        private readonly AttendanceScheduleService $schedules,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $pegawai = $this->pegawai($request);
        $attendance = $pegawai ? $this->currentAttendance($pegawai) : null;
        $isOnCuti = $this->cutiSchedule->isOnCuti($pegawai, $attendance);
        $isDayOff = $this->shiftHolidays->isDayOff($pegawai, $attendance);
        $shift = $isOnCuti || $isDayOff ? null : ($attendance?->shift ?? $pegawai?->shift);
        $office = OfficeLocation::query()->oldest('id')->first(['id', 'name', 'address']);
        $schedule = $this->schedules->resolve($pegawai?->id, $shift, $attendance?->tanggal_presensi ?? now());

        return response()->json([
            'data' => [
                'user' => [
                    'name' => $pegawai?->nama ?: $request->user()->name,
                    'email' => $pegawai?->email ?: $request->user()->email,
                    'can_access_account' => $request->user()->can(AccessPermission::MOBILE_ACCOUNT),
                    'has_profile_photo' => $this->profilePhotoPath($request) !== null,
                ],
                'date' => [
                    'value' => now()->toDateString(),
                    'label' => now()->locale('id')->translatedFormat('l, d F Y'),
                ],
                'schedule' => [
                    'shift_name' => $isOnCuti ? 'CUTI' : ($isDayOff ? 'Hari Libur' : $shift?->nama),
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'schedule_note' => $schedule['note'],
                    'location_name' => $office?->name,
                ],
                'attendance' => $this->attendancePayload($pegawai, $attendance, $office, $isOnCuti),
            ],
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $pegawai = $this->pegawai($request);
        $attendance = $pegawai ? $this->currentAttendance($pegawai) : null;
        $attendance?->loadMissing([
            'shift',
            'clockInLocation:id,name,address,latitude,longitude,radius_meters',
            'clockOutLocation:id,name,address,latitude,longitude,radius_meters',
        ]);
        $office = OfficeLocation::query()->oldest('id')->first(['id', 'name', 'address']);

        return response()->json([
            'data' => $this->attendancePayload($pegawai, $attendance, $office, $this->cutiSchedule->isOnCuti($pegawai, $attendance)),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $pegawai = $this->pegawai($request);
        $items = $pegawai?->presensis()
            ->with('shift')
            ->latest('tanggal_presensi')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Presensi $attendance): array => [
                'id' => $attendance->id,
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('d M Y'),
                'shift_name' => $attendance->shift?->nama,
                'clock_in' => $attendance->clock_in_at?->format('H:i'),
                'clock_out' => $attendance->clock_out_at?->format('H:i'),
                'is_day_off' => $attendance->is_day_off,
                'late_minutes' => $attendance->lateMinutes(),
                'early_departure_minutes' => $attendance->earlyDepartureMinutes(),
            ])
            ->values()
            ->all() ?? [];

        return response()->json(['data' => $items]);
    }

    public function account(Request $request): JsonResponse
    {
        $user = $request->user();
        $pegawai = $user->pegawai()->with(['jabatan:id,nama', 'unitKerja:id,nama'])->first();

        return response()->json([
            'data' => [
                'name' => $pegawai?->nama ?: $user->name,
                'email' => $pegawai?->email ?: $user->email,
                'nik' => $pegawai?->nik,
                'profession' => $pegawai?->profesi,
                'position' => $pegawai?->jabatan?->nama,
                'unit' => $pegawai?->unitKerja?->nama,
                'phone' => $pegawai?->nomor_telepon,
                'has_employee_profile' => $pegawai !== null,
                'has_photo' => $this->profilePhotoPath($request) !== null,
                'photo_updated_at' => $user->profile_photo_updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function updatePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $oldPath = $user->profile_photo_path;
        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'local');
        if (! $path) {
            throw ValidationException::withMessages(['photo' => 'Foto belum berhasil disimpan. Silakan coba kembali.']);
        }

        $user->forceFill([
            'profile_photo_path' => $path,
            'profile_photo_updated_at' => now(),
        ])->save();

        if ($oldPath && $oldPath !== $path && Str::startsWith($oldPath, "profile-photos/{$user->id}/") && ! str_contains($oldPath, '..')) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui dan siap digunakan untuk verifikasi wajah.',
            'updated_at' => $user->profile_photo_updated_at?->toIso8601String(),
        ]);
    }

    public function photo(Request $request): StreamedResponse
    {
        $path = $this->profilePhotoPath($request);
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function attendancePayload(?Pegawai $pegawai, ?Presensi $attendance, ?OfficeLocation $office, bool $isOnCuti): array
    {
        $dayOffReason = $this->shiftHolidays->dayOffReason($pegawai, $attendance);
        $isDayOff = $dayOffReason !== null;
        $shift = $isOnCuti || $isDayOff ? null : ($attendance?->shift ?? $pegawai?->shift);
        $date = $isOnCuti ? now() : ($attendance?->tanggal_presensi ?? now());
        $schedule = $this->schedules->resolve($pegawai?->id, $shift, $date);
        $clockIn = $attendance?->clock_in_at;
        $clockOut = $attendance?->clock_out_at;
        $status = match (true) {
            $isOnCuti => 'on_leave',
            $isDayOff => 'day_off',
            $clockOut !== null => 'complete',
            ! $pegawai || ! $shift || ! $office => 'unavailable',
            $clockIn === null => 'ready_to_clock_in',
            default => 'ready_to_clock_out',
        };

        return [
            'id' => $attendance?->id,
            'date' => $date->toDateString(),
            'date_label' => $date->locale('id')->translatedFormat('l, d F Y'),
            'status' => $status,
            'is_on_leave' => $isOnCuti,
            'is_day_off' => $isDayOff,
            'duration' => $this->durationLabel($clockIn, $clockOut),
            'notes' => $attendance?->notes ?? $dayOffReason,
            'shift' => [
                'name' => $isOnCuti ? 'CUTI' : ($isDayOff ? 'Hari Libur' : $shift?->nama),
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'schedule_note' => $schedule['note'],
            ],
            'office' => [
                'name' => $office?->name,
                'address' => $office?->address,
            ],
            'clock_in' => $clockIn ? [
                'time' => $clockIn->format('H:i'),
                'date_time_label' => $clockIn->locale('id')->translatedFormat('d M Y, H:i').' WIB',
                'address' => $attendance?->clock_in_address,
                'face_verified' => $attendance?->face_verified_at !== null,
                'face_similarity' => $attendance?->face_similarity,
                'late_minutes' => $attendance?->lateMinutes() ?? 0,
            ] : null,
            'clock_out' => $clockOut ? [
                'time' => $clockOut->format('H:i'),
                'date_time_label' => $clockOut->locale('id')->translatedFormat('d M Y, H:i').' WIB',
                'address' => $attendance?->clock_out_address,
                'face_verified' => $attendance?->clock_out_face_verified_at !== null,
                'face_similarity' => $attendance?->clock_out_face_similarity,
                'early_departure_minutes' => $attendance?->earlyDepartureMinutes() ?? 0,
            ] : null,
        ];
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

        return $hours > 0 ? $hours.' jam '.($minutes % 60).' menit' : $minutes.' menit';
    }

    private function pegawai(Request $request): ?Pegawai
    {
        return $request->user()?->pegawai()
            ->with('shift')
            ->first(['id', 'user_id', 'shift_id', 'nama', 'email']);
    }

    private function currentAttendance(Pegawai $pegawai): ?Presensi
    {
        $today = $this->schedules->clockInRecord($pegawai);
        if ($today) {
            return $today;
        }

        $previous = $pegawai->presensis()
            ->with('shift')
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

        return ! $previous->clock_out_at || now()->lte($scheduledEnd->copy()->addHours(4)) ? $previous : null;
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
}
