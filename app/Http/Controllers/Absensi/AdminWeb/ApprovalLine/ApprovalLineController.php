<?php

namespace App\Http\Controllers\Absensi\AdminWeb\ApprovalLine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\ApprovalLine\SaveApprovalLineRequest;
use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Services\Absensi\AdminWeb\ApprovalLine\ApprovalLineService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalLineController extends Controller
{
    public function __construct(private readonly ApprovalLineService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(ApprovalLine::types())],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $type = $filters['type'] ?? ApprovalLine::TYPE_CUTI;
        $search = trim($filters['search'] ?? '');
        $users = $this->service->approverUsers();
        $configuredIds = DB::table('approval_line_jabatan')->where('type', $type)->pluck('jabatan_id');

        return Inertia::render('absensi/admin-web/approval-line/Index', [
            'lines' => ApprovalLine::query()->where('type', $type)
                ->with(['jabatans.unitKerja', 'steps.user'])
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                    $query->whereHas('jabatans', fn (Builder $jabatan) => $jabatan->where('nama', 'like', "%{$search}%"))
                        ->orWhereHas('jabatans.unitKerja', fn (Builder $unit) => $unit->where('nama', 'like', "%{$search}%"))
                        ->orWhereHas('steps.user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"));
                }))
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)->withQueryString()
                ->through(fn (ApprovalLine $line) => [
                    'id' => $line->id,
                    'type' => $line->type,
                    'jabatans' => $line->jabatans->map(fn (Jabatan $jabatan) => [
                        'id' => $jabatan->id, 'nama' => $jabatan->nama, 'unit' => $jabatan->unitKerja?->nama,
                        'aktif' => $jabatan->aktif && $jabatan->unitKerja?->aktif,
                    ])->values(),
                    'steps' => $line->steps->map(fn ($step) => [
                        'user_id' => $step->user_id, 'name' => $step->user?->name ?? 'Pengguna tidak tersedia', 'email' => $step->user?->email,
                        'level' => $step->level, 'available' => $step->user !== null,
                    ])->values(),
                    'updated_at' => $line->updated_at->format('d M Y, H:i'),
                ]),
            'jabatanOptions' => Jabatan::query()->where('aktif', true)->whereHas('unitKerja', fn (Builder $unit) => $unit->where('aktif', true))
                ->with('unitKerja:id,nama')->orderBy('nama')->get()->map(fn (Jabatan $jabatan) => [
                    'id' => $jabatan->id, 'nama' => $jabatan->nama, 'unit' => $jabatan->unitKerja->nama,
                    'configured' => $configuredIds->contains($jabatan->id),
                ]),
            'userOptions' => $users,
            'overview' => [
                'configured' => $configuredIds->count(),
                'positions' => Jabatan::query()->where('aktif', true)->whereHas('unitKerja', fn (Builder $unit) => $unit->where('aktif', true))->count(),
                'approvers' => $users->count(),
            ],
            'filters' => ['type' => $type, 'search' => $search],
            'typeOptions' => collect(ApprovalLine::types())->map(fn (string $type) => ['value' => $type, 'label' => ucfirst($type)]),
            'can' => [
                'create' => $request->user()->can(AccessPermission::APPROVAL_LINE_CREATE),
                'update' => $request->user()->can(AccessPermission::APPROVAL_LINE_UPDATE),
                'delete' => $request->user()->can(AccessPermission::APPROVAL_LINE_DELETE),
            ],
        ]);
    }

    public function store(SaveApprovalLineRequest $request): RedirectResponse
    {
        $line = $this->service->save($request->validated());

        return back()->with('success', "Approval line {$line->type} berhasil ditambahkan.");
    }

    public function update(SaveApprovalLineRequest $request, ApprovalLine $approvalLine): RedirectResponse
    {
        abort_unless(in_array($approvalLine->type, ApprovalLine::types(), true), 404);
        $this->service->save($request->validated(), $approvalLine);

        return back()->with('success', 'Approval line diperbarui. Pengajuan yang sedang berjalan tetap menggunakan urutan sebelumnya.');
    }

    public function destroy(ApprovalLine $approvalLine): RedirectResponse
    {
        abort_unless(in_array($approvalLine->type, ApprovalLine::types(), true), 404);
        $this->service->delete($approvalLine);

        return back()->with('success', 'Approval line dihapus. Riwayat dan pengajuan yang sedang berjalan tetap tersimpan.');
    }
}
