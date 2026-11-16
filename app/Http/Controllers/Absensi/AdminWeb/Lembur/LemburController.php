<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Lembur;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\DecideLemburRequest;
use App\Http\Requests\Absensi\AdminWeb\Lembur\CancelAdminLemburRequest;
use App\Http\Requests\Absensi\AdminWeb\Lembur\StoreAdminLemburRequest;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LemburController extends Controller
{
    public function index(Request $request, LemburService $service): Response
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'request_kind' => ['nullable', Rule::in(['all', 'overtime', 'usage'])],
            'compensation' => ['nullable', Rule::in(['pay', 'time_off'])],
            'unit' => ['nullable', 'string', 'max:255'],
            'pegawai_id' => ['nullable', 'integer', 'exists:pegawais,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = [
            'search' => trim($input['search'] ?? ''),
            'start_date' => $input['start_date'] ?? now()->startOfMonth()->toDateString(),
            'end_date' => $input['end_date'] ?? now()->endOfMonth()->toDateString(),
            'status' => $input['status'] ?? '',
            'request_kind' => $input['request_kind'] ?? 'usage',
            'compensation' => $input['compensation'] ?? '', 'unit' => $input['unit'] ?? '',
            'pegawai_id' => isset($input['pegawai_id']) ? (int) $input['pegawai_id'] : '',
        ];
        if ($filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal akhir harus sama atau setelah tanggal mulai.']);
        }
        $search = $filters['search'];
        $query = LemburRequest::query()->whereBetween('tanggal_lembur', [$filters['start_date'], $filters['end_date']])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('nama_pegawai', 'like', "%{$search}%")->orWhere('nama_unit', 'like', "%{$search}%")
                ->orWhere('alasan', 'like', "%{$search}%")
                ->orWhereHas('pegawai', fn (Builder $query) => $query->where('nik', 'like', "%{$search}%"))));
        $query->when($filters['request_kind'] !== 'all', fn (Builder $query) => $query->where('request_kind', $filters['request_kind']));
        foreach (['status', 'compensation', 'pegawai_id'] as $field) {
            if ($filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        $query->when($filters['unit'] !== '', fn (Builder $query) => $query->where('nama_unit', $filters['unit']));
        $overview = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'overtime_minutes' => (int) (clone $query)->where('request_kind', 'overtime')->where('status', 'approved')->sum('duration_minutes'),
            'usage_minutes' => (int) (clone $query)->where('request_kind', 'usage')->where('status', 'approved')->sum('duration_minutes'),
        ];

        return Inertia::render('absensi/admin-web/lembur/Index', [
            'requests' => $query->latest('tanggal_lembur')->latest('id')->paginate(15)->withQueryString()->through(fn ($item) => [
                'id' => $item->id, 'name' => $item->nama_pegawai, 'unit' => $item->nama_unit, 'position' => $item->nama_jabatan,
                'date' => $item->tanggal_lembur->toDateString(), 'shift_name' => $item->nama_shift,
                'request_kind' => $item->request_kind, 'usage_type' => $item->usage_type, 'compensation' => $item->compensation,
                'usage_start' => $item->usage_start_at?->format('d M Y, H:i'),
                'usage_end' => $item->usage_end_at?->format('d M Y, H:i'),
                'duration_minutes' => $item->duration_minutes, 'status' => $item->status, 'reason' => $item->alasan,
            ]),
            'employeeOptions' => Pegawai::query()->with('unitKerja')->orderBy('nama')->orderBy('id')->get()->map(fn ($pegawai) => [
                'value' => $pegawai->id, 'label' => $pegawai->nama, 'has_account' => $pegawai->user_id !== null,
                'shift_id' => $pegawai->shift_id, 'description' => implode(' / ', array_filter([$pegawai->nik, $pegawai->unitKerja?->nama])),
            ]),
            'shifts' => Shift::query()->where('is_day_off', false)->orderBy('nama')->get()->map(fn ($shift) => [
                'value' => $shift->id, 'label' => $shift->nama,
                'description' => substr($shift->jam_masuk, 0, 5).' - '.substr($shift->jam_keluar, 0, 5),
            ]),
            'units' => LemburRequest::query()->select('nama_unit')->distinct()->orderBy('nama_unit')->pluck('nama_unit'),
            'filters' => $filters, 'overview' => $overview, 'today' => now()->toDateString(),
            'can' => ['create' => $request->user()->can(AccessPermission::LEMBUR_CREATE)],
        ]);
    }

    public function store(StoreAdminLemburRequest $request, LemburService $service): RedirectResponse
    {
        $data = $request->validated();
        $pegawai = Pegawai::query()->with('user')->findOrFail($data['pegawai_id']);
        if (! $pegawai->user) {
            throw ValidationException::withMessages(['pegawai_id' => 'Pegawai belum terhubung dengan akun pengguna.']);
        }
        $lembur = $service->submit($pegawai->user, $data, recordedBy: $request->user());

        return to_route('absensi.admin-web.lembur.show', $lembur)
            ->with('success', 'Lembur pegawai berhasil dicatat dan diteruskan sesuai approval line.');
    }

    public function show(Request $request, LemburRequest $lemburRequest, LemburService $service): Response|JsonResponse
    {
        $lemburRequest->load(['approvals', 'sources', 'recordedBy', 'cancelledBy']);
        $allowApproved = app(ConfigurationService::class)->current()->cancel_approved_lembur;

        $detail = [
            'lembur' => [
                'id' => $lemburRequest->id, 'name' => $lemburRequest->nama_pegawai,
                'unit' => $lemburRequest->nama_unit, 'position' => $lemburRequest->nama_jabatan,
                'date' => $lemburRequest->tanggal_lembur->toDateString(), 'status' => $lemburRequest->status,
                'request_kind' => $lemburRequest->request_kind, 'usage_type' => $lemburRequest->usage_type,
                'shift_name' => $lemburRequest->nama_shift,
                'shift_start' => $lemburRequest->shift_start_at->format('d M Y, H:i'),
                'shift_end' => $lemburRequest->shift_end_at->format('d M Y, H:i'),
                'before_start' => $lemburRequest->before_start_at?->format('d M Y, H:i'),
                'before_end' => $lemburRequest->before_end_at?->format('d M Y, H:i'),
                'after_start' => $lemburRequest->after_start_at?->format('d M Y, H:i'),
                'after_end' => $lemburRequest->after_end_at?->format('d M Y, H:i'),
                'before_break_minutes' => $lemburRequest->before_break_minutes,
                'after_break_minutes' => $lemburRequest->after_break_minutes,
                'usage_start' => $lemburRequest->usage_start_at?->format('d M Y, H:i'),
                'usage_end' => $lemburRequest->usage_end_at?->format('d M Y, H:i'),
                'duration_minutes' => $lemburRequest->duration_minutes, 'compensation' => $lemburRequest->compensation,
                'reason' => $lemburRequest->alasan, 'created_at' => $lemburRequest->created_at->format('d M Y, H:i'),
                'decided_at' => $lemburRequest->decided_at?->format('d M Y, H:i'),
                'recorded_by' => $lemburRequest->recordedBy?->name,
                'cancelled_by' => $lemburRequest->cancelledBy?->name, 'cancellation_note' => $lemburRequest->cancellation_note,
                'current_level' => $lemburRequest->current_level,
                'manual_balance_minutes' => $lemburRequest->manual_balance_minutes,
                'sources' => $lemburRequest->sources->map(fn ($source) => [
                    'id' => $source->id, 'date' => $source->tanggal_lembur->toDateString(), 'minutes' => (int) $source->pivot->minutes,
                ]),
                'approvals' => $lemburRequest->approvals->map(fn ($step) => [
                    'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                    'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                ]),
                'attachments' => collect($lemburRequest->attachments ?? [])->map(fn ($file, $index) => [
                    'name' => $file['name'], 'size' => $file['size'],
                    'url' => route('absensi.admin-web.lembur.attachment', [$lemburRequest->id, $index], absolute: false),
                ]),
            ],
            'balance' => $lemburRequest->pegawai_id ? $service->balances([$lemburRequest->pegawai_id])[$lemburRequest->pegawai_id] : null,
            'can' => [
                'cancel' => $request->user()->can(AccessPermission::LEMBUR_CANCEL)
                    && ($lemburRequest->status === 'pending' || ($lemburRequest->status === 'approved' && $allowApproved)),
                'decide' => $request->user()->can(AccessPermission::CUTI_APPROVE) && $lemburRequest->canBeDecidedBy($request->user()),
            ],
            'allowApprovedCancellation' => $allowApproved,
        ];

        return $request->expectsJson()
            ? response()->json($detail)
            : Inertia::render('absensi/admin-web/lembur/Show', $detail);
    }

    public function cancel(CancelAdminLemburRequest $request, LemburRequest $lemburRequest, LemburService $service): RedirectResponse
    {
        $service->cancelByAdmin($lemburRequest, $request->user(), $request->validated('note'));

        return back()->with('success', 'Pengajuan dibatalkan. Saldo lembur pegawai telah diperbarui.');
    }

    public function decide(DecideLemburRequest $request, LemburRequest $lemburRequest, LemburService $service): RedirectResponse
    {
        $service->decide($lemburRequest, $request->user(), $request->validated());

        return back()->with('success', 'Keputusan pengajuan lembur berhasil disimpan.');
    }

    public function attachment(LemburRequest $lemburRequest, int $attachment): StreamedResponse
    {
        $file = $lemburRequest->attachments[$attachment] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
