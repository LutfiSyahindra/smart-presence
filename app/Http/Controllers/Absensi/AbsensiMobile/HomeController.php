<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\OfficeLocation;
use App\Services\Absensi\CutiScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use App\Support\AccessPermission;
use App\Support\Portal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(
        private readonly CutiScheduleService $cutiSchedule,
        private readonly ShiftHolidayService $shiftHolidays,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $pegawai = $user?->pegawai()
            ->with('shift')
            ->first(['id', 'user_id', 'shift_id']);
        $todayPresensi = $pegawai?->presensis()
            ->with('shift')
            ->whereDate('tanggal_presensi', now()->toDateString())
            ->first(['id', 'pegawai_id', 'shift_id', 'tanggal_presensi', 'clock_in_at', 'clock_out_at', 'is_day_off']);

        if (! $todayPresensi && $pegawai) {
            $previousPresensi = $pegawai->presensis()
                ->with('shift')
                ->whereDate('tanggal_presensi', now()->subDay()->toDateString())
                ->whereNotNull('clock_in_at')
                ->latest('clock_in_at')
                ->first(['id', 'pegawai_id', 'shift_id', 'tanggal_presensi', 'clock_in_at', 'clock_out_at', 'is_day_off']);

            if (
                $previousPresensi?->shift
                && (string) $previousPresensi->shift->jam_keluar <= (string) $previousPresensi->shift->jam_masuk
                && ! $this->cutiSchedule->isOnCuti($pegawai, $previousPresensi)
            ) {
                $scheduledEnd = Carbon::createFromFormat(
                    'Y-m-d H:i',
                    $previousPresensi->tanggal_presensi->format('Y-m-d').' '.substr((string) $previousPresensi->shift->jam_keluar, 0, 5),
                )->addDay();

                if (! $previousPresensi->clock_out_at || now()->lte($scheduledEnd->addHours(4))) {
                    $todayPresensi = $previousPresensi;
                }
            }
        }

        $isOnCuti = $this->cutiSchedule->isOnCuti($pegawai, $todayPresensi);
        $isDayOff = $this->shiftHolidays->isDayOff($pegawai, $todayPresensi);
        $shift = $isOnCuti || $isDayOff ? null : ($todayPresensi?->shift ?? $pegawai?->shift);
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
                'shift_name' => $isOnCuti ? 'CUTI' : ($isDayOff ? 'Hari Libur' : $shift?->nama),
                'start_time' => $shift ? substr((string) $shift->jam_masuk, 0, 5) : null,
                'end_time' => $shift ? substr((string) $shift->jam_keluar, 0, 5) : null,
                'location_name' => $workLocation?->name,
            ],
            'todayAttendance' => [
                'is_on_leave' => $isOnCuti,
                'clocked_in_at' => $todayPresensi?->clock_in_at?->format('H:i'),
                'clocked_out_at' => $todayPresensi?->clock_out_at?->format('H:i'),
                'is_day_off' => $isDayOff,
            ],
        ]);
    }
}
