<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
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
        ]);
    }
}
