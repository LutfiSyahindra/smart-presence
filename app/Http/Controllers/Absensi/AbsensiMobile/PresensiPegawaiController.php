<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLine;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\User;
use App\Services\Absensi\AdminWeb\Presensi\PresensiService;
use App\Services\Absensi\ConfigurationService;
use App\Support\PresensiPegawaiAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PresensiPegawaiController extends Controller
{
    public function index(Request $request, PresensiService $service): Response
    {
        abort_unless(PresensiPegawaiAccess::allows($request->user()), 403);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'status' => ['nullable', Rule::in(['present', 'late', 'on-time', 'incomplete', 'overtime', 'day-off', 'cuti', 'approval-pending', 'approval-rejected', 'unrecorded'])],
        ]);
        $filters = $service->normalizeFilters($validated);
        $filters['status'] = $validated['status'] ?? null;
        // The configured scope is resolved from the authenticated user's current assignments.
        $scoped = [...$filters, 'pegawai_scope' => PresensiPegawaiAccess::employees($request->user())->select('pegawais.id')];
        $records = $service->paginate($scoped, 'absensi.mobile.presensi-pegawai.photo');
        $profilePhotos = PresensiPegawaiAccess::employees($request->user())
            ->whereKey(collect($records->items())->pluck('pegawai_id')->unique())
            ->with('user:id,profile_photo_path,profile_photo_updated_at')
            ->get(['id', 'user_id'])
            ->mapWithKeys(fn (Pegawai $pegawai) => [$pegawai->id => $this->profilePhotoUrl($pegawai)]);
        $records->through(fn (array $record) => [...$record, 'pegawai' => [
            ...$record['pegawai'], 'profile_photo_url' => $profilePhotos->get($record['pegawai_id']),
        ]]);

        $configuration = app(ConfigurationService::class)->current();

        return Inertia::render('absensi/absensi-mobile/PresensiPegawai', [
            'filters' => $filters,
            'records' => $records,
            'overview' => $service->overview($scoped),
            'unitName' => $request->user()->pegawai()->with('unitKerja:id,nama')->first()?->unitKerja?->nama,
            'scopeMode' => PresensiPegawaiAccess::scope($request->user()),
            'approvalTypeLabel' => ApprovalLine::typeLabels()[$configuration->mobile_presensi_pegawai_approval_type] ?? null,
            'today' => now()->toDateString(),
        ]);
    }

    public function profilePhoto(Request $request, Pegawai $pegawai): StreamedResponse
    {
        abort_unless(PresensiPegawaiAccess::allows($request->user()), 403);
        abort_unless(PresensiPegawaiAccess::employees($request->user())->whereKey($pegawai->id)->exists(), 404);
        $path = $this->profilePhotoPath($pegawai->user);
        abort_unless($path, 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function profilePhotoPath(?User $user): ?string
    {
        $path = $user?->profile_photo_path;

        return $path && Str::startsWith($path, "profile-photos/{$user->id}/")
            && ! str_contains($path, '..') && Storage::disk('local')->exists($path) ? $path : null;
    }

    private function profilePhotoUrl(Pegawai $pegawai): ?string
    {
        if (! $this->profilePhotoPath($pegawai->user)) {
            return null;
        }

        return route('absensi.mobile.presensi-pegawai.profile-photo', ['pegawai' => $pegawai->id], false)
            .'?v='.$pegawai->user->profile_photo_updated_at?->timestamp;
    }

    public function photo(Request $request, Presensi $presensi, string $type): StreamedResponse|RedirectResponse
    {
        abort_unless(PresensiPegawaiAccess::allows($request->user()), 403);
        abort_unless(PresensiPegawaiAccess::employees($request->user())->whereKey($presensi->pegawai_id)->exists(), 404);
        abort_unless(in_array($type, ['in', 'out'], true), 404);
        $path = $type === 'in' ? $presensi->clock_in_photo_path : $presensi->clock_out_photo_path;
        abort_if(! $path || str_contains($path, '..'), 404);
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return redirect()->away($path);
        }
        $path = ltrim($path, '/');
        if (Str::startsWith($path, 'storage/')) {
            $path = Str::after($path, 'storage/');
        }
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        abort(404);
    }
}
