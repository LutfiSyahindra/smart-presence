<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StoreClockInRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ClockInController extends Controller
{
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
            'profilePhotoUrl' => $this->profilePhotoUrl($request),
        ]);
    }

    public function store(StoreClockInRequest $request): RedirectResponse
    {
        $pegawai = $this->pegawai($request);
        if (! $pegawai) {
            throw ValidationException::withMessages([
                'attendance' => 'Profil pegawai belum terhubung. Hubungi administrator.',
            ]);
        }

        $existing = $this->todayPresensi($pegawai);
        if ($existing?->is_day_off) {
            throw ValidationException::withMessages([
                'attendance' => 'Hari ini tercatat sebagai hari libur. Clock-in tidak diperlukan.',
            ]);
        }
        if ($existing?->clock_in_at) {
            throw ValidationException::withMessages([
                'attendance' => 'Clock-in hari ini sudah tercatat dan tidak dapat dikirim ulang.',
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
        $notes = $existing?->notes;
        if ($outsideRadius) {
            $notes = $notes
                ? $notes."\n\nCatatan clock-in di luar radius: ".$outsideNote
                : $outsideNote;
        }

        $path = $request->file('photo')->store("presensi-photos/{$pegawai->id}/clock-in", 'local');
        if (! $path) {
            throw ValidationException::withMessages([
                'photo' => 'Foto belum berhasil disimpan. Silakan ambil ulang foto.',
            ]);
        }

        try {
            DB::transaction(function () use ($data, $distance, $existing, $notes, $office, $path, $pegawai, $request): void {
                $presensi = $existing ?? new Presensi;
                $presensi->fill([
                    'pegawai_id' => $pegawai->id,
                    'shift_id' => $pegawai->shift_id,
                    'tanggal_presensi' => now()->toDateString(),
                    'clock_in_at' => now(),
                    'clock_in_location_id' => $office->id,
                    'clock_in_latitude' => $data['latitude'],
                    'clock_in_longitude' => $data['longitude'],
                    'clock_in_address' => $outsideRadius
                        ? sprintf('Di luar radius %s (%s)', $office->name, $this->formatDistance($distance))
                        : ($office->address ?: $office->name),
                    'clock_in_photo_path' => $path,
                    'is_day_off' => false,
                    'notes' => $notes,
                    'updated_by' => $request->user()->id,
                ])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);

            throw ValidationException::withMessages([
                'attendance' => 'Clock-in belum berhasil disimpan. Silakan coba kembali.',
            ]);
        }

        return to_route('absensi.mobile.home')->with(
            'success',
            $outsideRadius
                ? 'Clock-in berhasil dikirim dengan catatan lokasi di luar radius.'
                : 'Clock-in berhasil. Selamat bekerja!',
        );
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
        return $pegawai->presensis()
            ->whereDate('tanggal_presensi', now()->toDateString())
            ->first();
    }

    private function profilePhotoUrl(Request $request): ?string
    {
        $user = $request->user();
        $path = $user?->profile_photo_path;

        if (
            ! $user
            || ! $user->can(AccessPermission::MOBILE_ACCOUNT)
            || ! $path
            || ! Str::startsWith($path, "profile-photos/{$user->id}/")
            || str_contains($path, '..')
            || ! Storage::disk('local')->exists($path)
        ) {
            return null;
        }

        return route('absensi.mobile.account.photo.show', absolute: false).'?v='.$user->profile_photo_updated_at?->timestamp;
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
