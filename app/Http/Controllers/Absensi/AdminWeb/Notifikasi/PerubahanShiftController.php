<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\DecidePerubahanShiftRequest;
use App\Models\PerubahanShiftRequest;
use App\Services\Absensi\AbsensiMobile\PerubahanShiftService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PerubahanShiftController extends Controller
{
    public function show(Request $request, PerubahanShiftRequest $perubahanShiftRequest): Response
    {
        $item = $perubahanShiftRequest;
        abort_unless($item->canBeViewedBy($request->user()), 403);

        return Inertia::render('absensi/admin-web/notifikasi/PerubahanShiftDetail', [
            'shiftRequest' => [
                'id' => $item->id, 'name' => $item->nama_pegawai, 'position' => $item->nama_jabatan, 'unit' => $item->nama_unit,
                'date' => $item->tanggal_perubahan->toDateString(), 'status' => $item->status,
                'original_shift' => $item->original_shift, 'requested_shift' => $item->requested_shift, 'reason' => $item->alasan,
                'current_level' => $item->current_level, 'created_at' => $item->created_at->format('d M Y, H:i'),
                'can_decide' => $request->user()->can(AccessPermission::CUTI_APPROVE) && $item->canBeDecidedBy($request->user()),
                'approvals' => $item->approvals->map(fn ($step) => [
                    'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status, 'note' => $step->note,
                    'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                ]),
            ],
        ]);
    }

    public function decide(DecidePerubahanShiftRequest $request, PerubahanShiftRequest $perubahanShiftRequest, PerubahanShiftService $service): RedirectResponse
    {
        $service->decide($perubahanShiftRequest, $request->user(), $request->validated());

        return back()->with('success', 'Keputusan pengajuan perubahan shift berhasil disimpan.');
    }
}
