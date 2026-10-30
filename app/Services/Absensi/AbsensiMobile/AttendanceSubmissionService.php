<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Exceptions\FaceVerificationException;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockInRequest;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockOutRequest;
use App\Models\AttendanceSubmission;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\CutiScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use App\ValueObjects\FaceVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttendanceSubmissionService
{
    public function __construct(
        private readonly FaceVerifier $faceVerifier,
        private readonly AttendanceLocationVerifier $locationVerifier,
        private readonly CutiScheduleService $cutiSchedule,
        private readonly ShiftHolidayService $shiftHolidays,
        private readonly AttendanceScheduleService $schedules,
    ) {}

    /** @return array{attendance: ?Presensi, message: string, outside_radius: bool, submission?: AttendanceSubmission} */
    public function clockIn(StoreClockInRequest $request): array
    {
        if (config('attendance.queue_enabled')) {
            return app(QueuedAttendanceService::class)->submit($request, 'clock-in');
        }

        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            $this->fail('attendance', 'Profil pegawai belum terhubung. Hubungi administrator.');
        }

        $existing = $this->todayPresensi($pegawai);
        $this->cutiSchedule->ensureCanAttend($pegawai, $existing);
        if ($this->shiftHolidays->isDayOff($pegawai, $existing)) {
            $this->fail('attendance', 'Hari ini tercatat sebagai hari libur. Clock-in tidak diperlukan.');
        }
        if ($existing?->clock_in_at) {
            $this->fail('attendance', 'Clock-in hari ini sudah tercatat dan tidak dapat dikirim ulang.');
        }

        $office = $this->office();
        if (! $office) {
            $this->fail('attendance', 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
        }

        $data = $request->validated();
        $verifiedLocation = $this->locationVerifier->verify($request, 'clock-in');
        $distance = $this->distanceInMeters(
            $verifiedLocation->latitude,
            $verifiedLocation->longitude,
            (float) $office->latitude,
            (float) $office->longitude,
        );
        $outsideRadius = $distance > $office->radius_meters;
        $notes = $this->validatedOutsideNotes($request, $data, $existing?->notes, $outsideRadius, 'clock-in');
        $profilePhotoPath = $this->profilePhotoPath($request);
        if (! $profilePhotoPath) {
            $this->fail('photo', 'Foto profil wajah belum tersedia. Buka menu Akun dan tambahkan foto profil terlebih dahulu.');
        }

        $verification = $this->verifyFace($request, $profilePhotoPath);
        if (! $verification->matched) {
            $this->fail('photo', 'Wajah tidak cocok dengan foto profil. Pastikan Anda menggunakan akun sendiri lalu coba verifikasi kembali.');
        }

        $path = $request->file('photo')?->store("presensi-photos/{$pegawai->id}/clock-in", 'local');
        if (! $path) {
            $this->fail('photo', 'Foto belum berhasil disimpan. Pastikan kamera aktif lalu coba verifikasi kembali.');
        }

        try {
            $attendance = DB::transaction(function () use ($distance, $existing, $notes, $office, $outsideRadius, $path, $pegawai, $request, $verification, $verifiedLocation): Presensi {
                Pegawai::query()->lockForUpdate()->findOrFail($pegawai->id);
                $existing = $this->todayPresensi($pegawai);
                if ($existing?->clock_in_at) {
                    $this->fail('attendance', 'Anda sudah melakukan clock in. Muat ulang halaman presensi.');
                }
                $this->cutiSchedule->ensureCanAttend($pegawai, $existing);
                $this->shiftHolidays->ensureCanAttend($pegawai, $existing);

                $presensi = $existing ?? new Presensi;
                $presensi->fill([
                    'pegawai_id' => $pegawai->id,
                    'shift_id' => $existing?->shift_id ?? $pegawai->shift_id,
                    'tanggal_presensi' => $existing?->tanggal_presensi ?? now()->toDateString(),
                    'clock_in_at' => now(),
                    'clock_in_location_id' => $office->id,
                    'clock_in_latitude' => $verifiedLocation->latitude,
                    'clock_in_longitude' => $verifiedLocation->longitude,
                    'clock_in_accuracy' => $verifiedLocation->accuracy,
                    'clock_in_location_sample_count' => $verifiedLocation->sampleCount,
                    'clock_in_location_verified_at' => now(),
                    'clock_in_address' => $outsideRadius
                        ? sprintf('Di luar radius %s (%s)', $office->name, $this->formatDistance($distance))
                        : ($office->address ?: $office->name),
                    'clock_in_photo_path' => $path,
                    'face_verified_at' => now(),
                    'face_similarity' => $verification->similarity,
                    'face_verification_provider' => $verification->provider,
                    'is_day_off' => false,
                    'notes' => $notes,
                    'updated_by' => $request->user()->id,
                ])->save();

                if ($outsideRadius) {
                    $approval = app(PresensiApprovalService::class);
                    $approval->submit($presensi, 'clock-in', $approval->snapshot($request->user(), (string) $request->input('outside_radius_note')));
                }

                return $presensi->refresh();
            });
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);
            $this->fail('attendance', 'Clock-in belum berhasil disimpan. Silakan coba kembali.');
        }

        $this->locationVerifier->consumeChallenge($request, 'clock-in');

        return [
            'attendance' => $attendance,
            'outside_radius' => $outsideRadius,
            'message' => $outsideRadius
                ? 'Clock-in di luar radius tersimpan dan menunggu persetujuan.'
                : 'Clock-in berhasil. Selamat bekerja!',
        ];
    }

    /** @return array{attendance: ?Presensi, message: string, outside_radius: bool, submission?: AttendanceSubmission} */
    public function clockOut(StoreClockOutRequest $request): array
    {
        if (config('attendance.queue_enabled')) {
            return app(QueuedAttendanceService::class)->submit($request, 'clock-out');
        }

        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            $this->fail('attendance', 'Profil pegawai belum terhubung. Hubungi administrator.');
        }

        $attendance = $this->attendanceForClockOut($pegawai);
        $this->cutiSchedule->ensureCanAttend($pegawai, $attendance);
        if ($this->shiftHolidays->isDayOff($pegawai, $attendance)) {
            $this->fail('attendance', 'Hari ini tercatat sebagai hari libur. Clock-out tidak diperlukan.');
        }
        if (! $attendance?->clock_in_at) {
            $this->fail('attendance', 'Lakukan clock-in terlebih dahulu sebelum clock-out.');
        }
        if ($attendance->clock_out_at) {
            $this->fail('attendance', 'Clock-out sudah tercatat dan tidak dapat dikirim ulang.');
        }
        if (now()->lt($attendance->clock_in_at)) {
            $this->fail('attendance', 'Waktu clock-out tidak boleh lebih awal dari waktu clock-in.');
        }

        $office = $this->office();
        if (! $office) {
            $this->fail('attendance', 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
        }

        $data = $request->validated();
        $verifiedLocation = $this->locationVerifier->verify($request, 'clock-out');
        $distance = $this->distanceInMeters(
            $verifiedLocation->latitude,
            $verifiedLocation->longitude,
            (float) $office->latitude,
            (float) $office->longitude,
        );
        $outsideRadius = $distance > $office->radius_meters;
        $notes = $this->validatedOutsideNotes($request, $data, $attendance->notes, $outsideRadius, 'clock-out');
        $profilePhotoPath = $this->profilePhotoPath($request);
        if (! $profilePhotoPath) {
            $this->fail('photo', 'Foto profil wajah belum tersedia. Buka menu Akun dan tambahkan foto profil terlebih dahulu.');
        }

        $verification = $this->verifyFace($request, $profilePhotoPath);
        if (! $verification->matched) {
            $this->fail('photo', 'Wajah tidak cocok dengan foto profil. Pastikan Anda menggunakan akun sendiri lalu coba verifikasi kembali.');
        }

        $path = $request->file('photo')?->store("presensi-photos/{$pegawai->id}/clock-out", 'local');
        if (! $path) {
            $this->fail('photo', 'Foto belum berhasil disimpan. Pastikan kamera aktif lalu coba verifikasi kembali.');
        }

        try {
            $attendance = DB::transaction(function () use ($attendance, $distance, $notes, $office, $outsideRadius, $path, $pegawai, $request, $verification, $verifiedLocation): Presensi {
                Pegawai::query()->lockForUpdate()->findOrFail($pegawai->id);
                $this->cutiSchedule->ensureCanAttend($pegawai, $attendance);

                $lockedAttendance = Presensi::query()->lockForUpdate()->findOrFail($attendance->id);
                if ($lockedAttendance->clock_out_at) {
                    $this->fail('attendance', 'Clock-out sudah tercatat dan tidak dapat dikirim ulang.');
                }

                $lockedAttendance->fill([
                    'clock_out_at' => now(),
                    'clock_out_location_id' => $office->id,
                    'clock_out_latitude' => $verifiedLocation->latitude,
                    'clock_out_longitude' => $verifiedLocation->longitude,
                    'clock_out_accuracy' => $verifiedLocation->accuracy,
                    'clock_out_location_sample_count' => $verifiedLocation->sampleCount,
                    'clock_out_location_verified_at' => now(),
                    'clock_out_address' => $outsideRadius
                        ? sprintf('Di luar radius %s (%s)', $office->name, $this->formatDistance($distance))
                        : ($office->address ?: $office->name),
                    'clock_out_photo_path' => $path,
                    'clock_out_face_verified_at' => now(),
                    'clock_out_face_similarity' => $verification->similarity,
                    'clock_out_face_verification_provider' => $verification->provider,
                    'notes' => $notes,
                    'updated_by' => $request->user()->id,
                ])->save();

                if ($outsideRadius) {
                    $approval = app(PresensiApprovalService::class);
                    $approval->submit($lockedAttendance, 'clock-out', $approval->snapshot($request->user(), (string) $request->input('outside_radius_note')));
                }

                return $lockedAttendance->refresh();
            });
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);
            $this->fail('attendance', 'Clock-out belum berhasil disimpan. Silakan coba kembali.');
        }

        $this->locationVerifier->consumeChallenge($request, 'clock-out');

        return [
            'attendance' => $attendance,
            'outside_radius' => $outsideRadius,
            'message' => $outsideRadius
                ? 'Clock-out di luar radius tersimpan dan menunggu persetujuan.'
                : 'Clock-out berhasil. Terima kasih atas kerja keras Anda!',
        ];
    }

    private function verifyFace(Request $request, string $profilePhotoPath): FaceVerificationResult
    {
        try {
            return $this->faceVerifier->compare(
                Storage::disk('local')->get($profilePhotoPath),
                (string) file_get_contents($request->file('photo')->getRealPath()),
            );
        } catch (FaceVerificationException $exception) {
            if ($exception->reason === FaceVerificationException::SERVICE_UNAVAILABLE) {
                report($exception);
                $this->fail('attendance', 'Layanan verifikasi wajah sedang tidak tersedia. Silakan coba beberapa saat lagi.');
            }

            $message = match ($exception->reason) {
                FaceVerificationException::INVALID_PROFILE_PHOTO => 'Foto profil tidak dapat digunakan untuk verifikasi. Perbarui foto profil dengan satu wajah yang terlihat jelas.',
                FaceVerificationException::MULTIPLE_FACES => 'Terdeteksi lebih dari satu wajah. Pastikan hanya Anda yang terlihat lalu coba verifikasi kembali.',
                FaceVerificationException::SPOOF_DETECTED => 'Wajah terindikasi berasal dari layar, cetakan, atau rekaman. Hadapkan wajah langsung ke kamera lalu coba kembali.',
                default => 'Wajah tidak terdeteksi dengan jelas. Hadapkan wajah ke kamera lalu coba verifikasi kembali.',
            };

            $this->fail('photo', $message);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('attendance', 'Layanan verifikasi wajah sedang tidak tersedia. Silakan coba beberapa saat lagi.');
        }
    }

    /** @param array<string, mixed> $data */
    private function validatedOutsideNotes(Request $request, array $data, ?string $notes, bool $outsideRadius, string $action): ?string
    {
        if (! $outsideRadius) {
            return $notes;
        }
        if (! $request->boolean('outside_radius_confirmed')) {
            $this->fail('outside_radius_confirmed', 'Anda berada di luar radius kantor. Tinjau lokasi dan berikan catatan sebelum melanjutkan.');
        }

        $outsideNote = trim((string) ($data['outside_radius_note'] ?? ''));
        if (mb_strlen($outsideNote) < 5) {
            $this->fail('outside_radius_note', 'Catatan lokasi di luar radius wajib diisi minimal 5 karakter.');
        }

        if (! $notes) {
            return $outsideNote;
        }

        return $notes."\n\nCatatan {$action} di luar radius: ".$outsideNote;
    }

    private function pegawai(Request $request): ?Pegawai
    {
        return $request->user()?->pegawai()
            ->with('shift')
            ->first(['id', 'user_id', 'shift_id']);
    }

    private function todayPresensi(Pegawai $pegawai): ?Presensi
    {
        return $this->schedules->clockInRecord($pegawai);
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

    private function office(): ?OfficeLocation
    {
        return OfficeLocation::query()->oldest('id')->first();
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

    private function formatDistance(float $meters): string
    {
        return $meters >= 1000
            ? number_format($meters / 1000, 1, ',', '.').' km dari kantor'
            : number_format($meters, 0, ',', '.').' m dari kantor';
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
