<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Presensi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Presensi\SavePresensiRequest;
use App\Models\Presensi;
use App\Services\Absensi\AdminWeb\Presensi\PresensiService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PresensiController extends Controller
{
    public function __construct(private readonly PresensiService $service) {}

    public function index(Request $request): Response
    {
        return $this->page($request, false);
    }

    public function manage(Request $request): Response
    {
        return $this->page($request, true);
    }

    public function store(SavePresensiRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), (int) $request->user()->id);

        return to_route('absensi.admin-web.presensi.manage')->with('success', 'Presensi pegawai berhasil ditambahkan.');
    }

    public function update(SavePresensiRequest $request, Presensi $presensi): RedirectResponse
    {
        $this->service->update($presensi, $request->validated(), (int) $request->user()->id);

        return back()->with('success', 'Presensi pegawai berhasil diperbarui.');
    }

    public function photo(Request $request, Presensi $presensi, string $type): StreamedResponse|RedirectResponse
    {
        abort_unless(
            $request->user()->can(AccessPermission::PRESENSI_VIEW)
                || $request->user()->can(AccessPermission::PRESENSI_MANAGE),
            403,
        );
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
                    'Cache-Control' => 'private, max-age=300',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }

    private function page(Request $request, bool $manage): Response
    {
        $filters = $this->service->normalizeFilters($request->only('search', 'date_from', 'date_to', 'status'));

        return Inertia::render('absensi/admin-web/presensi/Index', [
            'records' => $this->service->paginate($filters),
            'overview' => $this->service->overview($filters),
            'filters' => $filters,
            'manage' => $manage,
            'options' => $manage ? $this->service->formOptions() : null,
        ]);
    }
}
