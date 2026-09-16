<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Exceptions\FaceVerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockOutRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Support\AccessPermission;
use App\ValueObjects\FaceVerificationResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ClockOutController extends Controller
{
    public function __construct(private readonly FaceVerifier $faceVerifier) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            return to_route('absensi.mobile.home')->with('error', 'Profil pegawai belum terhubung. Hubungi administrator sebelum melakukan presensi.');
        }

        $attendance = $this->attendanceForClockOut($pegawai);
        if ($attendance?->is_day_off) {
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

        $attendance->loadMissing('shift:id,nama,jam_masuk,jam_keluar');

        return Inertia::render('absensi/absensi-mobile/ClockOut', [
            'schedule' => [
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
                'shift_name' => $attendance->shift?->nama,
                'start_time' => $attendance->shift ? substr((string) $attendance->shift->jam_masuk, 0, 5) : null,
                'end_time' => $attendance->shift ? substr((string) $attendance->shift->jam_keluar, 0, 5) : null,
            ],
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'address' => $office->address,
                'latitude' => $office->latitude,
                'longitude' => $office->longitude,
                'radius_meters' => $office->radius_meters,
            ],
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
            'shift:id,nama,jam_masuk,jam_keluar',
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

        return Inertia::render('absensi/absensi-mobile/ClockOutSuccess', [
            'attendance' => [
                'date' => $attendance->tanggal_presensi?->toDateString(),
                'date_label' => $attendance->tanggal_presensi?->locale('id')->translatedFormat('l, d F Y'),
                'clock_out_time' => $attendance->clock_out_at->format('H:i'),
                'early_departure_minutes' => $attendance->earlyDepartureMinutes(),
                'shift_name' => $attendance->shift?->nama,
                'start_time' => $attendance->shift ? substr((string) $attendance->shift->jam_masuk, 0, 5) : null,
                'end_time' => $attendance->shift ? substr((string) $attendance->shift->jam_keluar, 0, 5) : null,
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
        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            throw ValidationException::withMessages([
                'attendance' => 'Profil pegawai belum terhubung. Hubungi administrator.',
            ]);
        }

        $attendance = $this->attendanceForClockOut($pegawai);
        if ($attendance?->is_day_off) {
            throw ValidationException::withMessages([
                'attendance' => 'Hari ini tercatat sebagai hari libur. Clock-out tidak diperlukan.',
            ]);
        }
        if (! $attendance?->clock_in_at) {
            throw ValidationException::withMessages([
                'attendance' => 'Lakukan clock-in terlebih dahulu sebelum clock-out.',
            ]);
        }
        if ($attendance->clock_out_at) {
            throw ValidationException::withMessages([
                'attendance' => 'Clock-out sudah tercatat dan tidak dapat dikirim ulang.',
            ]);
        }
        if (now()->lt($attendance->clock_in_at)) {
            throw ValidationException::withMessages([
                'attendance' => 'Waktu clock-out tidak boleh lebih awal dari waktu clock-in.',
            ]);
        }

        $office = $this->office();
        if (! $office) {
            throw ValidationException::withMessages([
                'attendance' => 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.',
            ]);
        }

        $data = $request->validated();
        $distance = $this->distanceInMeters(
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $office->latitude,
            (float) $office->longitude,
        );
        $outsideRadius = $distance > $office->radius_meters;

        if ($outsideRadius && ! $request->boolean('outside_radius_confirmed')) {
            throw ValidationException::withMessages([
                'outside_radius_confirmed' => 'Anda berada di luar radius kantor. Tinjau lokasi dan berikan catatan sebelum melanjutkan.',
            ]);
        }

        $outsideNote = trim((string) ($data['outside_radius_note'] ?? ''));
        if ($outsideRadius && mb_strlen($outsideNote) < 5) {
            throw ValidationException::withMessages([
                'outside_radius_note' => 'Catatan lokasi di luar radius wajib diisi minimal 5 karakter.',
            ]);
        }

        $notes = $attendance->notes;
        if ($outsideRadius) {
            $notes = $notes
                ? $notes."\n\nCatatan clock-out di luar radius: ".$outsideNote
                : $outsideNote;
        }

        $profilePhotoPath = $this->profilePhotoPath($request);
        if (! $profilePhotoPath) {
            throw ValidationException::withMessages([
                'photo' => 'Foto profil wajah belum tersedia. Buka menu Akun dan tambahkan foto profil terlebih dahulu.',
            ]);
        }

        $verification = $this->verifyFace($request, $profilePhotoPath);
        if (! $verification->matched) {
            throw ValidationException::withMessages([
                'photo' => 'Wajah tidak cocok dengan foto profil. Pastikan Anda menggunakan akun sendiri lalu coba verifikasi kembali.',
            ]);
        }

        $path = $request->file('photo')->store("presensi-photos/{$pegawai->id}/clock-out", 'local');
        if (! $path) {
            throw ValidationException::withMessages([
                'photo' => 'Foto belum berhasil disimpan. Pastikan kamera aktif lalu coba verifikasi kembali.',
            ]);
        }

        try {
            DB::transaction(function () use ($attendance, $data, $distance, $notes, $office, $outsideRadius, $path, $request, $verification): void {
                $lockedAttendance = Presensi::query()->lockForUpdate()->findOrFail($attendance->id);
                if ($lockedAttendance->clock_out_at) {
                    throw ValidationException::withMessages([
                        'attendance' => 'Clock-out sudah tercatat dan tidak dapat dikirim ulang.',
                    ]);
                }

                $lockedAttendance->fill([
                    'clock_out_at' => now(),
                    'clock_out_location_id' => $office->id,
                    'clock_out_latitude' => $data['latitude'],
                    'clock_out_longitude' => $data['longitude'],
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
            });
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);

            throw ValidationException::withMessages([
                'attendance' => 'Clock-out belum berhasil disimpan. Silakan coba kembali.',
            ]);
        }

        return to_route('absensi.mobile.clock-out.success')->with(
            'success',
            $outsideRadius
                ? 'Clock-out berhasil dikirim dengan catatan lokasi di luar radius.'
                : 'Clock-out berhasil. Terima kasih atas kerja keras Anda!',
        );
    }

    private function verifyFace(StoreClockOutRequest $request, string $profilePhotoPath): FaceVerificationResult
    {
        try {
            return $this->faceVerifier->compare(
                Storage::disk('local')->get($profilePhotoPath),
                (string) file_get_contents($request->file('photo')->getRealPath()),
            );
        } catch (FaceVerificationException $exception) {
            if ($exception->reason === FaceVerificationException::SERVICE_UNAVAILABLE) {
                report($exception);

                throw ValidationException::withMessages([
                    'attendance' => 'Layanan verifikasi wajah sedang tidak tersedia. Silakan coba beberapa saat lagi.',
                ]);
            }

            $message = match ($exception->reason) {
                FaceVerificationException::INVALID_PROFILE_PHOTO => 'Foto profil tidak dapat digunakan untuk verifikasi. Perbarui foto profil dengan satu wajah yang terlihat jelas.',
                FaceVerificationException::MULTIPLE_FACES => 'Terdeteksi lebih dari satu wajah. Pastikan hanya Anda yang terlihat lalu coba verifikasi kembali.',
                FaceVerificationException::SPOOF_DETECTED => 'Wajah terindikasi berasal dari layar, cetakan, atau rekaman. Hadapkan wajah langsung ke kamera lalu coba kembali.',
                default => 'Wajah tidak terdeteksi dengan jelas. Hadapkan wajah ke kamera lalu coba verifikasi kembali.',
            };

            throw ValidationException::withMessages(['photo' => $message]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'attendance' => 'Layanan verifikasi wajah sedang tidak tersedia. Silakan coba beberapa saat lagi.',
            ]);
        }
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

        if (! $previous?->shift) {
            return null;
        }

        return (string) $previous->shift->jam_keluar <= (string) $previous->shift->jam_masuk
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

        if (
            ! $user
            || ! $path
            || ! Str::startsWith($path, "profile-photos/{$user->id}/")
            || str_contains($path, '..')
            || ! Storage::disk('local')->exists($path)
        ) {
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
}
