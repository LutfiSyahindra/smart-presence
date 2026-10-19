<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Cuti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Cuti\DecideCutiRequest;
use App\Http\Requests\Absensi\AdminWeb\Cuti\StoreCutiRequest;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Services\Absensi\AdminWeb\MasterData\Cuti\MasterCutiService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CutiController extends Controller
{
    public function __construct(private readonly CutiService $service) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('super-admin');
        $canSubmit = $user->can(AccessPermission::ADMIN_ACCESS);
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in($isAdmin ? ['mine', 'inbox', 'history', 'all'] : ['mine', 'inbox', 'history'])],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $scope = $filters['scope'] ?? ($canSubmit ? 'mine' : 'inbox');
        $search = trim($filters['search'] ?? '');
        $inbox = fn (Builder $query) => $query->where('status', 'pending')->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'pending'));

        $query = CutiRequest::query()->with('approvals');
        match ($scope) {
            'mine' => $query->where('user_id', $user->id),
            'inbox' => $inbox($query),
            'history' => $query->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->whereIn('status', ['approved', 'rejected'])),
            'all' => $query,
        };

        $pegawai = $user->pegawai()->with(['jabatan.unitKerja'])->first();
        $leaveOptions = $pegawai ? app(MasterCutiService::class)->options($pegawai) : [];
        $line = $pegawai?->jabatan_id ? ApprovalLine::query()->with('steps.user')
            ->whereHas('jabatans', fn (Builder $query) => $query->whereKey($pegawai->jabatan_id))->where('type', ApprovalLine::TYPE_CUTI)->first() : null;
        $unavailableReason = match (true) {
            ! $pegawai?->jabatan_id => 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.',
            ! $pegawai->jabatan?->aktif || ! $pegawai->jabatan?->unitKerja?->aktif => 'Unit atau jabatan Anda tidak aktif.',
            ! $line || $line->steps->isEmpty() => 'Approval line cuti untuk jabatan Anda belum diatur.',
            $line->steps->contains('user_id', $user->id) => 'Approval line memuat akun Anda sendiri. Hubungi administrator.',
            $line->steps->contains(fn ($step) => $step->user === null) => 'Ada pengguna approver yang tidak tersedia. Hubungi administrator.',
            $leaveOptions === [] => 'Belum ada jenis cuti yang diberikan kepada Anda. Hubungi administrator.',
            default => null,
        };

        return Inertia::render('absensi/admin-web/cuti/Index', [
            'requests' => $query->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                    $query->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_jabatan', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%");
                }))
                ->latest('id')->paginate(10)->withQueryString()->through(fn (CutiRequest $cuti) => [
                    'id' => $cuti->id, 'nama_pegawai' => $cuti->nama_pegawai, 'jabatan' => $cuti->nama_jabatan, 'unit' => $cuti->nama_unit,
                    'tanggal_mulai' => $cuti->tanggal_mulai->format('Y-m-d'), 'tanggal_selesai' => $cuti->tanggal_selesai->format('Y-m-d'),
                    'alasan' => $cuti->alasan, 'status' => $cuti->status, 'current_level' => $cuti->current_level,
                    'nama_cuti' => $cuti->nama_cuti, 'jumlah_hari' => $cuti->jumlah_hari,
                    'created_at' => $cuti->created_at->format('d M Y, H:i'),
                    'can_decide' => $cuti->status === 'pending' && (int) $cuti->user_id !== (int) $user->id
                        && $cuti->approvals->contains(fn ($step) => $step->level === $cuti->current_level && $step->status === 'pending' && (int) $step->user_id === (int) $user->id),
                    'approvals' => $cuti->approvals->map(fn ($step) => [
                        'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                        'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                    ])->values(),
                ]),
            'filters' => ['scope' => $scope, 'status' => $filters['status'] ?? '', 'search' => $search],
            'overview' => [
                'mine_pending' => CutiRequest::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
                'inbox' => $inbox(CutiRequest::query())->count(),
                'mine_approved' => CutiRequest::query()->where('user_id', $user->id)->where('status', 'approved')->count(),
            ],
            'employee' => $pegawai ? ['nama' => $pegawai->nama, 'jabatan' => $pegawai->jabatan?->nama, 'unit' => $pegawai->jabatan?->unitKerja?->nama] : null,
            'approvalPreview' => $line?->steps->map(fn ($step) => ['level' => $step->level, 'name' => $step->user?->name ?? 'Pengguna tidak tersedia'])->values() ?? [],
            'unavailableReason' => $unavailableReason,
            'canViewAll' => $isAdmin,
            'canSubmit' => $canSubmit,
            'leaveOptions' => $leaveOptions,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreCutiRequest $request): RedirectResponse
    {
        $this->service->submit($request->user(), $request->validated());

        return to_route('absensi.admin-web.cuti.index')->with('success', 'Pengajuan cuti dikirim ke approver tingkat 1.');
    }

    public function decide(DecideCutiRequest $request, CutiRequest $cutiRequest): RedirectResponse
    {
        $cuti = $this->service->decide($cutiRequest, $request->user(), $request->validated());
        $message = match ($cuti->status) {
            'approved' => 'Seluruh approver telah menyetujui. Pengajuan cuti disetujui.',
            'rejected' => 'Pengajuan cuti ditolak dan alur approval dihentikan.',
            default => "Persetujuan tersimpan. Pengajuan diteruskan ke approver tingkat {$cuti->current_level}.",
        };

        return back()->with('success', $message);
    }
}
