<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Models\PerubahanShiftRequest;
use App\Models\PresensiRequest;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\AbsensiMobile\PerubahanShiftService;
use App\Services\Absensi\AbsensiMobile\PresensiApprovalService;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NotifikasiController extends Controller
{
    public function index(Request $request, string $category = 'butuh-approval'): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'request_type' => ['nullable', Rule::in(['all', 'cuti', 'lembur', 'perubahan-shift', 'presensi'])],
        ]);
        $search = trim($filters['search'] ?? '');
        $requestType = $category === 'butuh-approval' ? ($filters['request_type'] ?? 'all') : 'all';
        $inbox = CutiRequest::query()->awaitingApprovalBy($user);
        $approved = CutiRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $lemburInbox = LemburRequest::query()->awaitingApprovalBy($user);
        $lemburApproved = LemburRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $shiftInbox = PerubahanShiftRequest::query()->awaitingApprovalBy($user);
        $shiftApproved = PerubahanShiftRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn ($steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $presensiInbox = PresensiRequest::query()->awaitingApprovalBy($user);
        $presensiApproved = PresensiRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn ($steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $requestTypeCounts = ['presensi' => (clone $presensiInbox)->count(),
            'cuti' => (clone $inbox)->count(),
            'lembur' => (clone $lemburInbox)->count(),
            'perubahan-shift' => (clone $shiftInbox)->count(),
        ];
        $overview = [
            'inbox' => array_sum($requestTypeCounts),
            'approved' => (clone $approved)->count() + (clone $lemburApproved)->count() + (clone $shiftApproved)->count() + (clone $presensiApproved)->count(),
        ];
        $queries = ['cuti' => $category === 'disetujui' ? $approved : $inbox, 'lembur' => $category === 'disetujui' ? $lemburApproved : $lemburInbox];
        $queries['perubahan-shift'] = $category === 'disetujui' ? $shiftApproved : $shiftInbox;
        $queries['presensi'] = $category === 'disetujui' ? $presensiApproved : $presensiInbox;
        foreach ($queries as $type => $query) {
            $query->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $query->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_jabatan', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%");
            }))->select('id')->selectRaw("'{$type}' as request_type")
                ->addSelect($category === 'disetujui' ? 'decided_at as sort_at' : 'created_at as sort_at');
        }
        $requests = DB::query()->fromSub($queries['cuti']->unionAll($queries['lembur'])->unionAll($queries['perubahan-shift'])->unionAll($queries['presensi'])->toBase(), 'pengajuan')
            ->when($requestType !== 'all', fn ($query) => $query->where('request_type', $requestType))
            ->orderByDesc('sort_at')->orderByDesc('id')->orderBy('request_type')->paginate(10)->withQueryString();
        $rows = $requests->getCollection();
        $cutiRows = CutiRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'cuti')->pluck('id'))->get()->keyBy('id');
        $lemburRows = LemburRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'lembur')->pluck('id'))->get()->keyBy('id');
        $shiftRows = PerubahanShiftRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'perubahan-shift')->pluck('id'))->get()->keyBy('id');

        $presensiRows = PresensiRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'presensi')->pluck('id'))->get()->keyBy('id');

        return Inertia::render('absensi/admin-web/notifikasi/Index', [
            'requests' => $requests->through(function ($row) use ($cutiRows, $lemburRows, $shiftRows, $presensiRows, $user, $category) {
                $cuti = match ($row->request_type) {
                    'cuti' => $cutiRows[$row->id], 'lembur' => $lemburRows[$row->id], 'presensi' => $presensiRows[$row->id], default => $shiftRows[$row->id]
                };
                $isLembur = $row->request_type === 'lembur';
                $isShift = $row->request_type === 'perubahan-shift';
                $isPresensi = $row->request_type === 'presensi';
                $start = $isPresensi ? $cuti->tanggal_presensi : ($isShift ? $cuti->tanggal_perubahan : ($isLembur ? $cuti->tanggal_lembur : $cuti->tanggal_mulai));
                $end = $isPresensi || $isShift || $isLembur ? $start : $cuti->tanggal_selesai;

                return [
                    'request_type' => $row->request_type,
                    'action' => $isPresensi ? $cuti->action : null,
                    'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
                    'tanggal_mulai' => $start->format('Y-m-d'), 'tanggal_selesai' => $end->format('Y-m-d'),
                    'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
                    'nama_cuti' => $isPresensi || $isLembur || $isShift ? null : $cuti->nama_cuti, 'jumlah_hari' => $isPresensi || $isLembur || $isShift ? null : $cuti->jumlah_hari,
                    'original_shift' => $isShift ? $cuti->original_shift : null, 'requested_shift' => $isShift ? $cuti->requested_shift : null,
                    'duration_minutes' => $isLembur ? $cuti->duration_minutes : null,
                    'request_kind' => $isLembur ? $cuti->request_kind : null,
                    'usage_type' => $isLembur ? $cuti->usage_type : null,
                    'shift_name' => $isLembur ? $cuti->nama_shift : null,
                    'created_at' => $cuti->created_at->format('d M Y, H:i'),
                    'can_decide' => $user->can(AccessPermission::CUTI_APPROVE) && $category === 'butuh-approval',
                    'approvals' => $cuti->approvals->map(fn ($step) => [
                        'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                        'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                    ])->values(),
                ];
            }),
            'category' => $category,
            'filters' => ['search' => $search, 'request_type' => $requestType],
            'overview' => $overview,
            'requestTypeCounts' => $requestTypeCounts,
        ]);
    }

    public function approveMany(Request $request, CutiService $service, LemburService $lemburService, PerubahanShiftService $shiftService, PresensiApprovalService $presensiService): RedirectResponse
    {
        $data = $request->validate([
            'request_ids' => ['nullable', 'array', 'max:100'],
            'request_ids.*' => ['required', 'integer', 'distinct'],
            'lembur_request_ids' => ['nullable', 'array', 'max:100'],
            'lembur_request_ids.*' => ['required', 'integer', 'distinct'],
            'presensi_request_ids' => ['nullable', 'array', 'max:100'],
            'presensi_request_ids.*' => ['required', 'integer', 'distinct'],
            'perubahan_shift_request_ids' => ['nullable', 'array', 'max:100'],
            'perubahan_shift_request_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'request_ids.required_without' => 'Pilih setidaknya satu pengajuan untuk disetujui.',
            'request_ids.max' => 'Maksimal 100 pengajuan dapat disetujui sekaligus.',
            'request_ids.*.distinct' => 'Pengajuan yang sama tidak boleh dipilih lebih dari sekali.',
            'lembur_request_ids.required_without' => 'Pilih setidaknya satu pengajuan untuk disetujui.',
            'lembur_request_ids.max' => 'Maksimal 100 pengajuan dapat disetujui sekaligus.',
            'lembur_request_ids.*.distinct' => 'Pengajuan lembur yang sama tidak boleh dipilih lebih dari sekali.',
        ]);

        $cutiIds = $data['request_ids'] ?? [];
        $lemburIds = $data['lembur_request_ids'] ?? [];
        $shiftIds = $data['perubahan_shift_request_ids'] ?? [];
        $presensiIds = $data['presensi_request_ids'] ?? [];
        $count = count($cutiIds) + count($lemburIds) + count($shiftIds) + count($presensiIds);
        if ($count < 1 || $count > 100) {
            throw ValidationException::withMessages(['request_ids' => 'Pilih 1 sampai 100 pengajuan untuk disetujui.']);
        }
        DB::transaction(function () use ($request, $service, $lemburService, $shiftService, $cutiIds, $lemburIds, $shiftIds, $presensiIds, $presensiService) {
            if ($cutiIds !== []) {
                $service->approveMany($request->user(), $cutiIds);
            }
            $lemburRequests = LemburRequest::query()->whereIn('id', $lemburIds)->orderBy('id')->lockForUpdate()->get();
            if ($lemburRequests->count() !== count($lemburIds)) {
                throw ValidationException::withMessages(['lembur_request_ids' => 'Ada pengajuan lembur yang tidak tersedia. Muat ulang daftar.']);
            }
            foreach ($lemburRequests as $lembur) {
                $lemburService->decide($lembur, $request->user(), ['decision' => 'approved']);
            }
            $shiftRequests = PerubahanShiftRequest::query()->whereIn('id', $shiftIds)->orderBy('id')->lockForUpdate()->get();
            if ($shiftRequests->count() !== count($shiftIds)) {
                throw ValidationException::withMessages(['perubahan_shift_request_ids' => 'Ada pengajuan perubahan shift yang tidak tersedia. Muat ulang daftar.']);
            }
            foreach ($shiftRequests as $shiftRequest) {
                $shiftService->decide($shiftRequest, $request->user(), ['decision' => 'approved']);
            }
            $presensiRequests = PresensiRequest::query()->whereIn('id', $presensiIds)->orderBy('id')->lockForUpdate()->get();
            if ($presensiRequests->count() !== count($presensiIds)) {
                throw ValidationException::withMessages(['presensi_request_ids' => 'Ada pengajuan presensi yang tidak tersedia. Muat ulang daftar.']);
            }
            foreach ($presensiRequests as $presensi) {
                $presensiService->decide($presensi, $request->user(), ['decision' => 'approved']);
            }
        });

        return back()->with('success', "Berhasil menyetujui {$count} pengajuan pada tingkat approval Anda.");
    }
}
