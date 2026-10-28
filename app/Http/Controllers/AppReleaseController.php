<?php

namespace App\Http\Controllers;

use App\Models\MobileAppRelease;
use App\Services\Mobile\AppReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppReleaseController extends Controller
{
    public function check(Request $request, AppReleaseService $service): JsonResponse
    {
        $data = $request->validate([
            'version_code' => ['required', 'integer', 'min:0', 'max:2100000000'],
            'sdk_int' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json($service->check((int) $data['version_code'], isset($data['sdk_int']) ? (int) $data['sdk_int'] : null))
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(MobileAppRelease $release, AppReleaseService $service): BinaryFileResponse
    {
        abort_unless($service->active()?->id === $release->id, 404);

        return self::file($release);
    }

    public static function file(MobileAppRelease $release): BinaryFileResponse
    {
        $path = Storage::disk('local')->path($release->path);
        abort_unless(is_file($path), 404, 'File APK tidak tersedia.');

        return response()->download($path, 'arsy-presensi-'.$release->version_code.'.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
