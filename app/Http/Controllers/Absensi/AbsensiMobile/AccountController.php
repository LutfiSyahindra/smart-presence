<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\UpdateProfilePhotoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $pegawai = $user->pegawai()->with(['jabatan:id,nama', 'unitKerja:id,nama'])->first();

        return Inertia::render('absensi/absensi-mobile/Account', [
            'profile' => [
                'name' => $pegawai?->nama ?: $user->name,
                'email' => $pegawai?->email ?: $user->email,
                'nik' => $pegawai?->nik,
                'profession' => $pegawai?->profesi,
                'position' => $pegawai?->jabatan?->nama,
                'unit' => $pegawai?->unitKerja?->nama,
                'phone' => $pegawai?->nomor_telepon,
                'has_employee_profile' => $pegawai !== null,
                'photo_url' => $this->photoUrl($request),
                'photo_updated_at' => $user->profile_photo_updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function updatePhoto(UpdateProfilePhotoRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldPath = $user->profile_photo_path;
        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'local');

        if (! $path) {
            return back()->withErrors(['photo' => 'Foto belum berhasil disimpan. Silakan coba kembali.']);
        }

        $user->forceFill([
            'profile_photo_path' => $path,
            'profile_photo_updated_at' => now(),
        ])->save();

        if (
            $oldPath
            && $oldPath !== $path
            && Str::startsWith($oldPath, "profile-photos/{$user->id}/")
            && ! str_contains($oldPath, '..')
        ) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Foto profil berhasil diperbarui dan siap digunakan untuk verifikasi wajah.');
    }

    public function photo(Request $request): StreamedResponse
    {
        $user = $request->user();
        $path = $user->profile_photo_path;
        $prefix = "profile-photos/{$user->id}/";

        abort_unless($path && Str::startsWith($path, $prefix) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function photoUrl(Request $request): ?string
    {
        $user = $request->user();
        $path = $user->profile_photo_path;

        if (
            ! $path
            || ! Str::startsWith($path, "profile-photos/{$user->id}/")
            || str_contains($path, '..')
            || ! Storage::disk('local')->exists($path)
        ) {
            return null;
        }

        return route('absensi.mobile.account.photo.show', absolute: false).'?v='.$user->profile_photo_updated_at?->timestamp;
    }
}
