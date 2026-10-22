<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotifikasiController extends Controller
{
    public function index(Request $request, string $category = 'butuh-approval'): Response
    {
        $user = $request->user();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:150']]);
        $search = trim($filters['search'] ?? '');
        $inbox = CutiRequest::query()->awaitingApprovalBy($user);
        $approved = CutiRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $overview = ['inbox' => (clone $inbox)->count(), 'approved' => (clone $approved)->count()];
        $query = $category === 'disetujui' ? $approved->orderByDesc('decided_at') : $inbox;

        return Inertia::render('absensi/admin-web/notifikasi/Index', [
            'requests' => $query->with('approvals')
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                    $query->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_jabatan', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%");
                }))
                ->latest('id')->paginate(10)->withQueryString()->through(fn (CutiRequest $cuti) => [
                    'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
                    'tanggal_mulai' => $cuti->tanggal_mulai->format('Y-m-d'), 'tanggal_selesai' => $cuti->tanggal_selesai->format('Y-m-d'),
                    'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
                    'nama_cuti' => $cuti->nama_cuti, 'jumlah_hari' => $cuti->jumlah_hari,
                    'created_at' => $cuti->created_at->format('d M Y, H:i'),
                    'can_decide' => $user->can(AccessPermission::CUTI_APPROVE) && $category === 'butuh-approval',
                    'approvals' => $cuti->approvals->map(fn ($step) => [
                        'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                        'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                    ])->values(),
                ]),
            'category' => $category,
            'filters' => ['search' => $search],
            'overview' => $overview,
        ]);
    }

    public function approveMany(Request $request, CutiService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1', 'max:100'],
            'request_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'request_ids.required' => 'Pilih setidaknya satu pengajuan untuk disetujui.',
            'request_ids.max' => 'Maksimal 100 pengajuan dapat disetujui sekaligus.',
            'request_ids.*.distinct' => 'Pengajuan yang sama tidak boleh dipilih lebih dari sekali.',
        ]);

        $count = $service->approveMany($request->user(), $data['request_ids']);

        return back()->with('success', "Berhasil menyetujui {$count} pengajuan pada tingkat approval Anda.");
    }
}
