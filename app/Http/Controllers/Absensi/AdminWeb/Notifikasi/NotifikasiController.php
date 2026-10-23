<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $lemburInbox = LemburRequest::query()->awaitingApprovalBy($user);
        $lemburApproved = LemburRequest::query()->where('status', 'approved')
            ->when(! $user->hasRole('super-admin'), fn (Builder $query) => $query->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->orWhereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'approved'));
            }));
        $overview = [
            'inbox' => (clone $inbox)->count() + (clone $lemburInbox)->count(),
            'approved' => (clone $approved)->count() + (clone $lemburApproved)->count(),
        ];
        $queries = ['cuti' => $category === 'disetujui' ? $approved : $inbox, 'lembur' => $category === 'disetujui' ? $lemburApproved : $lemburInbox];
        foreach ($queries as $type => $query) {
            $query->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $query->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_jabatan', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%");
            }))->select('id')->selectRaw("'{$type}' as request_type")
                ->addSelect($category === 'disetujui' ? 'decided_at as sort_at' : 'created_at as sort_at');
        }
        $requests = DB::query()->fromSub($queries['cuti']->unionAll($queries['lembur'])->toBase(), 'pengajuan')
            ->orderByDesc('sort_at')->orderByDesc('id')->orderBy('request_type')->paginate(10)->withQueryString();
        $rows = $requests->getCollection();
        $cutiRows = CutiRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'cuti')->pluck('id'))->get()->keyBy('id');
        $lemburRows = LemburRequest::query()->with('approvals')->whereIn('id', $rows->where('request_type', 'lembur')->pluck('id'))->get()->keyBy('id');

        return Inertia::render('absensi/admin-web/notifikasi/Index', [
            'requests' => $requests->through(function ($row) use ($cutiRows, $lemburRows, $user, $category) {
                $cuti = $row->request_type === 'cuti' ? $cutiRows[$row->id] : $lemburRows[$row->id];
                $isLembur = $row->request_type === 'lembur';

                return [
                    'request_type' => $row->request_type,
                    'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
                    'tanggal_mulai' => ($isLembur ? $cuti->tanggal_lembur : $cuti->tanggal_mulai)->format('Y-m-d'),
                    'tanggal_selesai' => ($isLembur ? $cuti->tanggal_lembur : $cuti->tanggal_selesai)->format('Y-m-d'),
                    'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
                    'nama_cuti' => $isLembur ? null : $cuti->nama_cuti, 'jumlah_hari' => $isLembur ? null : $cuti->jumlah_hari,
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
            'filters' => ['search' => $search],
            'overview' => $overview,
        ]);
    }

    public function approveMany(Request $request, CutiService $service, LemburService $lemburService): RedirectResponse
    {
        $data = $request->validate([
            'request_ids' => ['required_without:lembur_request_ids', 'array', 'max:100'],
            'request_ids.*' => ['required', 'integer', 'distinct'],
            'lembur_request_ids' => ['required_without:request_ids', 'array', 'max:100'],
            'lembur_request_ids.*' => ['required', 'integer', 'distinct'],
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
        $count = count($cutiIds) + count($lemburIds);
        if ($count < 1 || $count > 100) {
            throw ValidationException::withMessages(['request_ids' => 'Pilih 1 sampai 100 pengajuan untuk disetujui.']);
        }
        DB::transaction(function () use ($request, $service, $lemburService, $cutiIds, $lemburIds) {
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
        });

        return back()->with('success', "Berhasil menyetujui {$count} pengajuan pada tingkat approval Anda.");
    }
}
