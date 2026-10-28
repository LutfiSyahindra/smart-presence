<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting;

use App\Http\Controllers\AppReleaseController as PublicReleaseController;
use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\Mobile\AppReleaseService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppReleaseController extends Controller
{
    public function index(Request $request, AppReleaseService $service): Response
    {
        $active = $service->active();

        return Inertia::render('absensi/admin-web/setting/app-release/Index', [
            'releases' => MobileAppRelease::with(['uploader:id,name', 'publisher:id,name', 'withdrawer:id,name'])
                ->orderByDesc('version_code')->paginate(15)->through(fn (MobileAppRelease $release) => [
                    ...$service->metadata($release),
                    'status' => $active?->id === $release->id ? 'active' : ($release->published_at ? 'archived' : 'draft'),
                    'uploaded_by' => $release->uploader?->name, 'published_by' => $release->publisher?->name,
                    'withdrawn_by' => $release->withdrawer?->name, 'withdrawn_at' => $release->withdrawn_at?->toIso8601String(),
                    'created_at' => $release->created_at->toIso8601String(),
                    'certificate_sha256' => $release->certificate_sha256,
                    'preview_url' => route('absensi.admin-web.setting.app-releases.download', ['release' => $release->id], false),
                ]),
            'activeRelease' => $active ? $service->metadata($active) : null,
            'maxUploadMb' => (int) config('mobile.apk_max_kb') / 1024,
            'can' => [
                'manage' => $request->user()->can(AccessPermission::APP_RELEASES_MANAGE),
                'publish' => $request->user()->can(AccessPermission::APP_RELEASES_PUBLISH),
            ],
        ]);
    }

    public function store(Request $request, AppReleaseService $service): RedirectResponse
    {
        $data = $request->validate([
            ...$this->rules(), 'apk' => ['required', 'file', 'extensions:apk', 'max:'.config('mobile.apk_max_kb')],
        ]);
        $file = $data['apk'];
        unset($data['apk']);
        $service->upload($file, $data, $request->user());

        return back()->with('success', 'APK berhasil diverifikasi dan disimpan sebagai draf. Uji APK sebelum menerbitkannya.');
    }

    public function update(Request $request, MobileAppRelease $release, AppReleaseService $service): RedirectResponse
    {
        // Editing an active release changes its mandatory-update policy immediately.
        abort_if($release->published_at && ! $request->user()->can(AccessPermission::APP_RELEASES_PUBLISH), 403);
        $service->update($release, $request->validate($this->rules()));

        return back()->with('success', 'Catatan dan pengaturan pembaruan berhasil disimpan.');
    }

    public function publish(Request $request, MobileAppRelease $release, AppReleaseService $service): RedirectResponse
    {
        $service->publish($release, $request->user());

        return back()->with('success', 'Rilis diterbitkan. Pegawai dapat mengunduh dan memperbarui aplikasi.');
    }

    public function destroy(MobileAppRelease $release, AppReleaseService $service): RedirectResponse
    {
        $service->discard($release);

        return back()->with('success', 'Draf APK dihapus. Anda dapat mengunggah APK penggantinya.');
    }

    public function withdraw(Request $request, MobileAppRelease $release, AppReleaseService $service): RedirectResponse
    {
        $service->withdraw($release, $request->user());

        return back()->with('success', 'Rilis ditarik. Unduhan dan pemberitahuan pembaruan dihentikan.');
    }

    public function download(MobileAppRelease $release): BinaryFileResponse
    {
        return PublicReleaseController::file($release);
    }

    private function rules(): array
    {
        return [
            'release_notes' => ['required', 'string', 'max:10000'],
            'minimum_version_code' => ['required', 'integer', 'min:1', 'max:2100000000'],
            'required_at' => ['nullable', 'date'],
        ];
    }
}
