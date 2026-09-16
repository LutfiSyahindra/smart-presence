<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use App\Support\AccessPermission;
use App\Support\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $pegawai = $user?->pegawai()
            ->with('shift:id,nama,jam_masuk,jam_keluar')
            ->first(['id', 'user_id', 'shift_id']);
        $shift = $pegawai?->shift;
        $todayPresensi = $pegawai?->presensis()
            ->whereDate('tanggal_presensi', now()->toDateString())
            ->first(['id', 'pegawai_id', 'clock_in_at', 'clock_out_at', 'is_day_off']);
        $workLocation = OfficeLocation::query()
            ->oldest('id')
            ->first(['id', 'name']);
        $photoPath = $user?->profile_photo_path;
        $canAccessAccount = $user?->can(AccessPermission::MOBILE_ACCOUNT) ?? false;
        $hasPhoto = $canAccessAccount
            && $user
            && $photoPath
            && Str::startsWith($photoPath, "profile-photos/{$user->id}/")
            && ! str_contains($photoPath, '..')
            && Storage::disk('local')->exists($photoPath);

        return Inertia::render('absensi/absensi-mobile/Home', [
            'canAccessAdmin' => Portal::canAccessAdmin($request),
            'canAccessAccount' => $canAccessAccount,
            'profilePhotoUrl' => $hasPhoto
                ? route('absensi.mobile.account.photo.show', absolute: false).'?v='.$user->profile_photo_updated_at?->timestamp
                : null,
            'todaySchedule' => [
                'shift_name' => $shift?->nama,
                'start_time' => $shift ? substr((string) $shift->jam_masuk, 0, 5) : null,
                'end_time' => $shift ? substr((string) $shift->jam_keluar, 0, 5) : null,
                'location_name' => $workLocation?->name,
            ],
            'todayAttendance' => [
                'clocked_in_at' => $todayPresensi?->clock_in_at?->format('H:i'),
                'clocked_out_at' => $todayPresensi?->clock_out_at?->format('H:i'),
                'is_day_off' => $todayPresensi?->is_day_off ?? false,
            ],
        ]);
    }
}
