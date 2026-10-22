<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Support\AccessPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CutiApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'month' => ['nullable', 'date_format:Y-m'],
            'sort' => ['nullable', Rule::in(['oldest', 'newest'])],
        ]);
        $search = trim($filters['search'] ?? '');
        $month = $filters['month'] ?? '';
        $sort = $filters['sort'] ?? 'oldest';
        $pending = CutiRequest::query()->awaitingApprovalBy($request->user());
        $totalPending = (clone $pending)->count();

        $pending->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
            $query->where('nama_pegawai', 'like', "%{$search}%")
                ->orWhere('nama_unit', 'like', "%{$search}%")
                ->orWhere('nama_cuti', 'like', "%{$search}%");
        }));
        if ($month !== '') {
            $period = CarbonImmutable::createFromFormat('!Y-m', $month);
            $pending->whereDate('tanggal_mulai', '<=', $period->endOfMonth()->toDateString())
                ->whereDate('tanggal_selesai', '>=', $period->toDateString());
        }

        return Inertia::render('absensi/absensi-mobile/CutiApprovals', [
            'filters' => ['search' => $search, 'month' => $month, 'sort' => $sort],
            'totalPending' => $totalPending,
            'requests' => $pending->with('approvals')->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc')
                ->orderBy('id', $sort === 'newest' ? 'desc' : 'asc')->paginate(10)->withQueryString()->through(fn (CutiRequest $cuti) => [
                    'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
                    'nama_cuti' => $cuti->nama_cuti, 'jumlah_hari' => $cuti->jumlah_hari,
                    'tanggal_mulai' => $cuti->tanggal_mulai->toDateString(), 'tanggal_selesai' => $cuti->tanggal_selesai->toDateString(),
                    'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
                    'created_at' => $cuti->created_at->format('d M Y, H:i'), 'can_decide' => $request->user()->can(AccessPermission::MOBILE_CUTI_APPROVE),
                    'approvals' => $cuti->approvals->map(fn ($step) => [
                        'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                        'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                    ])->values(),
                ]),
        ]);
    }

    public function approveMany(Request $request, CutiService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1', 'max:100'],
            'request_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $count = $service->approveMany($request->user(), $data['request_ids']);

        return back()->with('success', "Berhasil menyetujui {$count} pengajuan pada tingkat persetujuan Anda.");
    }
}
