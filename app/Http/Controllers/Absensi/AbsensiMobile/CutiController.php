<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Cuti\DecideCutiRequest;
use App\Http\Requests\Absensi\AdminWeb\Cuti\StoreCutiRequest;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Services\Absensi\AdminWeb\MasterData\Cuti\MasterCutiService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CutiController extends Controller
{
    public function index(Request $request, MasterCutiService $masterService, ?CutiRequest $cutiRequest = null): Response
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'delegation'])],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
        ]);
        $scope = $filters['scope'] ?? 'mine';
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'] ?? now()->format('Y-m'));
        $user = $request->user();
        if ($cutiRequest?->exists) {
            $cutiRequest->load('approvals');
            abort_unless($user->hasRole('super-admin') || (int) $cutiRequest->user_id === (int) $user->id
                || $cutiRequest->approvals->contains('user_id', $user->id), 403);
        }
        $serialize = fn (CutiRequest $cuti) => [
            'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
            'nama_cuti' => $cuti->nama_cuti, 'jumlah_hari' => $cuti->jumlah_hari,
            'tanggal_mulai' => $cuti->tanggal_mulai->format('Y-m-d'), 'tanggal_selesai' => $cuti->tanggal_selesai->format('Y-m-d'),
            'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
            'created_at' => $cuti->created_at->format('d M Y, H:i'), 'can_cancel' => $cuti->canBeCancelledBy($user),
            'can_decide' => $cuti->status === 'pending' && (int) $cuti->user_id !== (int) $user->id
                && $cuti->approvals->contains(fn ($step) => $step->level === $cuti->current_level && $step->status === 'pending' && (int) $step->user_id === (int) $user->id),
            'approvals' => $cuti->approvals->map(fn ($step) => [
                'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
            ])->values(),
        ];
        $pegawai = $user->pegawai()->with('jabatan.unitKerja')->first();
        $options = $pegawai ? $masterService->options($pegawai) : [];
        $line = $pegawai?->jabatan_id ? ApprovalLine::query()->with('steps.user')
            ->where('type', ApprovalLine::TYPE_CUTI)
            ->whereHas('jabatans', fn (Builder $query) => $query->whereKey($pegawai->jabatan_id))->first() : null;
        $unavailableReason = match (true) {
            ! $pegawai?->jabatan_id => 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.',
            ! $pegawai->jabatan?->aktif || ! $pegawai->jabatan?->unitKerja?->aktif => 'Unit atau jabatan Anda tidak aktif. Hubungi administrator.',
            ! $line || $line->steps->isEmpty() => 'Alur persetujuan cuti untuk jabatan Anda belum diatur. Hubungi administrator.',
            $line->steps->contains('user_id', $user->id) => 'Alur persetujuan memuat akun Anda sendiri. Hubungi administrator.',
            $line->steps->contains(fn ($step) => $step->user === null) => 'Ada pemberi persetujuan yang tidak tersedia. Hubungi administrator.',
            $options === [] => 'Belum ada jenis cuti yang diberikan kepada Anda. Hubungi administrator.',
            default => null,
        };

        $annual = collect($options)->first(fn ($option) => $option['is_tahunan'] && $option['tahun'] === $month->year);
        $usage = $annual && $pegawai ? CutiRequest::query()->where('pegawai_id', $pegawai->id)
            ->where('master_cuti_id', $annual['id'])->where('is_tahunan', true)->where('tahun_cuti', $month->year)
            ->whereIn('status', ['pending', 'approved'])->selectRaw('status, SUM(jumlah_hari) AS days')->groupBy('status')->pluck('days', 'status') : collect();

        $query = CutiRequest::query()->with('approvals');
        if ($scope === 'mine') {
            $query->where('user_id', $user->id);
        } else {
            $query->where('user_id', '!=', $user->id)
                ->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id));
        }

        return Inertia::render('absensi/absensi-mobile/Cuti', [
            'filters' => ['scope' => $scope, 'month' => $month->format('Y-m'), 'status' => $filters['status'] ?? ''],
            'requests' => $query->whereDate('tanggal_mulai', '<=', $month->endOfMonth()->toDateString())
                ->whereDate('tanggal_selesai', '>=', $month->toDateString())
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest('id')->paginate(8)->withQueryString()->through($serialize),
            'initialRequest' => $cutiRequest?->exists ? $serialize($cutiRequest) : null,
            'balance' => $annual ? [
                'name' => $annual['nama'], 'year' => $month->year, 'total' => $annual['jumlah_hari'], 'available' => $annual['sisa'],
                'used' => (int) $usage->get('approved', 0), 'pending' => (int) $usage->get('pending', 0),
            ] : null,
            'delegationCount' => CutiRequest::query()->where('user_id', '!=', $user->id)->where('status', 'pending')
                ->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'pending'))->count(),
            'leaveOptions' => $options,
            'employee' => $pegawai ? ['nama' => $pegawai->nama, 'jabatan' => $pegawai->jabatan?->nama, 'unit' => $pegawai->jabatan?->unitKerja?->nama] : null,
            'approvalPreview' => $line?->steps->map(fn ($step) => ['level' => $step->level, 'name' => $step->user?->name ?? 'Pengguna tidak tersedia'])->values() ?? [],
            'unavailableReason' => $unavailableReason,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreCutiRequest $request, CutiService $service): RedirectResponse
    {
        $cuti = $service->submit($request->user(), $request->validated());

        return to_route('absensi.mobile.cuti.index', ['month' => $cuti->tanggal_mulai->format('Y-m')])
            ->with('success', 'Pengajuan cuti berhasil dikirim. Pantau persetujuannya di sini.');
    }

    public function cancel(Request $request, CutiRequest $cutiRequest, CutiService $service): RedirectResponse
    {
        $service->cancel($cutiRequest, $request->user());

        return back()->with('success', 'Pengajuan cuti dibatalkan. Saldo cuti tersedia kembali.');
    }

    public function decide(DecideCutiRequest $request, CutiRequest $cutiRequest, CutiService $service): RedirectResponse
    {
        $cuti = $service->decide($cutiRequest, $request->user(), $request->validated());

        return back()->with('success', match ($cuti->status) {
            'approved' => 'Pengajuan cuti telah disetujui seluruh pemberi persetujuan.',
            'rejected' => 'Pengajuan cuti ditolak. Catatan Anda telah disimpan.',
            default => 'Persetujuan disimpan dan diteruskan ke tingkat berikutnya.',
        });
    }
}
