<?php

namespace App\Http\Controllers\Absensi;

use App\Http\Controllers\Controller;
use App\Models\PresensiRequest;
use App\Services\Absensi\AbsensiMobile\PresensiApprovalService;
use App\Support\AccessPermission;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PresensiApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'scope' => ['nullable', Rule::in(['mine', 'delegation'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'sort' => ['nullable', Rule::in(['oldest', 'newest'])],
        ]);
        $scope = $filters['scope'] ?? 'delegation';
        $search = trim($filters['search'] ?? '');
        $month = $filters['month'] ?? '';
        $sort = $filters['sort'] ?? ($scope === 'mine' ? 'newest' : 'oldest');
        $query = $scope === 'mine' ? PresensiRequest::query()->where('user_id', $request->user()->id)
            : PresensiRequest::query()->awaitingApprovalBy($request->user());
        $totalRequests = (clone $query)->count();
        if ($month !== '') {
            $period = CarbonImmutable::createFromFormat('!Y-m', $month);
            $query->whereBetween('tanggal_presensi', [$period->toDateString(), $period->endOfMonth()->toDateString()]);
        }

        return Inertia::render('absensi/absensi-mobile/PresensiApprovals', [
            'scope' => $scope, 'search' => $search,
            'filters' => ['search' => $search, 'month' => $month, 'sort' => $sort],
            'totalRequests' => $totalRequests,
            'requests' => $query->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%")))
                ->with('approvals')->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc')
                ->orderBy('id', $sort === 'newest' ? 'desc' : 'asc')->paginate(15)->withQueryString()
                ->through(fn ($item) => $this->serialize($item, $request)),
        ]);
    }

    public function show(Request $request, PresensiRequest $presensiRequest): Response
    {
        abort_unless($presensiRequest->canBeViewedBy($request->user()), 403);
        $mobile = $request->routeIs('absensi.mobile.*');

        return Inertia::render($mobile ? 'absensi/absensi-mobile/PresensiApprovalDetail' : 'absensi/admin-web/notifikasi/PresensiDetail', [
            'presensiRequest' => $this->serialize($presensiRequest, $request),
        ]);
    }

    public function decide(Request $request, PresensiRequest $presensiRequest, PresensiApprovalService $service): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:1000']]);
        $service->decide($presensiRequest, $request->user(), $data);

        return back()->with('success', 'Keputusan persetujuan presensi berhasil disimpan.');
    }

    public function photo(Request $request, PresensiRequest $presensiRequest)
    {
        abort_unless($presensiRequest->canBeViewedBy($request->user()), 403);
        $path = $presensiRequest->photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'no-store, private']);
    }

    private function serialize(PresensiRequest $item, Request $request): array
    {
        return ['id' => $item->id, 'name' => $item->nama_pegawai, 'position' => $item->nama_jabatan, 'unit' => $item->nama_unit,
            'date' => $item->tanggal_presensi->toDateString(), 'action' => $item->action,
            'recorded_at' => $item->recorded_at->format('d/m/Y H:i'), 'location' => $item->location,
            'reason' => $item->alasan, 'status' => $item->status, 'current_level' => $item->current_level,
            'can_decide' => $item->canBeDecidedBy($request->user())
                && ($request->routeIs('absensi.mobile.*') || $request->user()->can(AccessPermission::CUTI_APPROVE)),
            'approvals' => $item->approvals->map(fn ($step) => ['level' => $step->level, 'name' => $step->approver_name,
                'status' => $step->status, 'note' => $step->note, 'decided_at' => $step->decided_at?->format('d/m/Y H:i')])->values()];
    }
}
