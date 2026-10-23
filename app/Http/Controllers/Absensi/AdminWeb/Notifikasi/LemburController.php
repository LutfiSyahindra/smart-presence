<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\DecideLemburRequest;
use App\Models\LemburRequest;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LemburController extends Controller
{
    public function show(Request $request, LemburRequest $lemburRequest): Response
    {
        abort_unless($lemburRequest->canBeViewedBy($request->user()), 403);
        $lemburRequest->load(['approvals', 'sources']);

        return Inertia::render('absensi/admin-web/notifikasi/LemburDetail', [
            'lembur' => [
                'request_kind' => $lemburRequest->request_kind, 'usage_type' => $lemburRequest->usage_type,
                'usage_start' => $lemburRequest->usage_start_at?->format('d M Y, H:i'),
                'usage_end' => $lemburRequest->usage_end_at?->format('d M Y, H:i'),
                'sources' => $lemburRequest->sources->map(fn ($source) => ['id' => $source->id,
                    'date' => $source->tanggal_lembur->format('Y-m-d'), 'minutes' => (int) $source->pivot->minutes])->values(),
                'id' => $lemburRequest->id, 'name' => $lemburRequest->nama_pegawai,
                'position' => $lemburRequest->nama_jabatan, 'unit' => $lemburRequest->nama_unit,
                'date' => $lemburRequest->tanggal_lembur->format('Y-m-d'),
                'shift_name' => $lemburRequest->nama_shift,
                'shift_start' => $lemburRequest->shift_start_at->format('d M Y, H:i'),
                'shift_end' => $lemburRequest->shift_end_at->format('d M Y, H:i'),
                'before_start' => $lemburRequest->before_start_at?->format('d M Y, H:i'),
                'before_end' => $lemburRequest->before_end_at?->format('d M Y, H:i'),
                'after_start' => $lemburRequest->after_start_at?->format('d M Y, H:i'),
                'after_end' => $lemburRequest->after_end_at?->format('d M Y, H:i'),
                'before_minutes' => $lemburRequest->before_minutes, 'before_break_minutes' => $lemburRequest->before_break_minutes,
                'after_minutes' => $lemburRequest->after_minutes, 'after_break_minutes' => $lemburRequest->after_break_minutes,
                'duration_minutes' => $lemburRequest->duration_minutes, 'compensation' => $lemburRequest->compensation,
                'reason' => $lemburRequest->alasan, 'status' => $lemburRequest->status,
                'current_level' => $lemburRequest->current_level, 'created_at' => $lemburRequest->created_at->format('d M Y, H:i'),
                'can_decide' => $request->user()->can(AccessPermission::CUTI_APPROVE) && $lemburRequest->canBeDecidedBy($request->user()),
                'attachments' => collect($lemburRequest->attachments ?? [])->map(fn ($file, $index) => [
                    'name' => $file['name'], 'size' => $file['size'],
                    'url' => route('absensi.admin-web.notifikasi.lembur.attachment', ['lemburRequest' => $lemburRequest->id, 'attachment' => $index], absolute: false),
                ])->values(),
                'approvals' => $lemburRequest->approvals->map(fn ($step) => [
                    'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                    'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                ])->values(),
            ],
        ]);
    }

    public function decide(DecideLemburRequest $request, LemburRequest $lemburRequest, LemburService $service): RedirectResponse
    {
        $service->decide($lemburRequest, $request->user(), $request->validated());

        return back()->with('success', 'Keputusan pengajuan lembur berhasil disimpan.');
    }
}
