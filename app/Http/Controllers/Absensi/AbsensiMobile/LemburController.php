<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\DecideLemburRequest;
use App\Http\Requests\Absensi\AbsensiMobile\StoreLemburRequest;
use App\Http\Requests\Absensi\AbsensiMobile\StoreLemburUsageRequest;
use App\Models\LemburRequest;
use App\Models\Shift;
use App\Services\Absensi\AbsensiMobile\LemburService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LemburController extends Controller
{
    private const PREVIEW_CONTENT_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'txt' => 'text/plain; charset=UTF-8',
        'csv' => 'text/plain; charset=UTF-8',
    ];

    public function index(Request $request, LemburService $service, ?LemburRequest $lemburRequest = null): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2100-12'],
            'scope' => ['nullable', Rule::in(['mine', 'delegation'])],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
        ]);
        $user = $request->user();
        $scope = $filters['scope'] ?? ($lemburRequest?->exists && (int) $lemburRequest->user_id !== (int) $user->id ? 'delegation' : 'mine');
        if ($lemburRequest?->exists) {
            abort_unless($lemburRequest->canBeViewedBy($user), 403);
        }
        $month = $filters['month'] ?? ($lemburRequest?->tanggal_lembur?->format('Y-m') ?? now()->format('Y-m'));
        $allPeriods = $scope === 'delegation' && empty($filters['month']);
        $query = LemburRequest::query();
        if ($scope === 'mine') {
            $query->where('user_id', $user->id);
        } else {
            $query->where('user_id', '!=', $user->id)->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id));
        }
        if (! $allPeriods) {
            $period = CarbonImmutable::createFromFormat('!Y-m', $month);
            $query->whereDate('tanggal_lembur', '>=', $period->toDateString())
                ->whereDate('tanggal_lembur', '<=', $period->endOfMonth()->toDateString());
        }
        $summary = [
            'count' => (clone $query)->count(),
            'submitted_minutes' => (int) (clone $query)->where('request_kind', 'overtime')->where('status', '!=', 'cancelled')->sum('duration_minutes'),
            'approved_minutes' => (int) (clone $query)->where('request_kind', 'overtime')->where('status', 'approved')->sum('duration_minutes'),
            'pending_count' => (clone $query)->where('status', 'pending')->count(),
        ];

        return Inertia::render('absensi/absensi-mobile/Lembur', [
            'month' => $month, 'today' => now()->toDateString(), 'scope' => $scope, 'allPeriods' => $allPeriods,
            'status' => $filters['status'] ?? '', 'summary' => $summary,
            'balance' => $service->balance($user),
            'approvalCount' => LemburRequest::query()->awaitingApprovalBy($user)->count(),
            'requests' => $query->with(['approvals', 'sources'])->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest('tanggal_lembur')->latest('id')->paginate(10)->withQueryString()->through(fn ($lembur) => $this->serialize($request, $lembur)),
            'initialRequest' => $lemburRequest?->exists ? $this->serialize($request, $lemburRequest) : null,
        ]);
    }

    public function create(Request $request, LemburService $service): Response
    {
        $context = $service->context($request->user());

        return Inertia::render('absensi/absensi-mobile/LemburCreate', [
            'today' => now()->toDateString(),
            'employee' => $context['pegawai'] ? [
                'name' => $context['pegawai']->nama, 'position' => $context['jabatan']?->nama, 'unit' => $context['jabatan']?->unitKerja?->nama,
            ] : null,
            'defaultShiftId' => $context['pegawai']?->shift_id,
            'shifts' => Shift::query()->orderBy('nama')->get()->map(fn ($shift) => [
                'id' => $shift->id, 'name' => $shift->nama, 'start_time' => substr($shift->jam_masuk, 0, 5), 'end_time' => substr($shift->jam_keluar, 0, 5),
            ]),
            'approvalPreview' => $context['line']?->steps->map(fn ($step) => [
                'level' => $step->level, 'name' => $step->user?->name ?? 'Pengguna tidak tersedia',
            ])->values() ?? [],
            'unavailableReason' => $context['unavailable_reason'],
        ]);
    }

    public function store(StoreLemburRequest $request, LemburService $service): RedirectResponse
    {
        $lembur = $service->submit($request->user(), $request->validated());

        return to_route('absensi.mobile.lembur.index', ['month' => $lembur->tanggal_lembur->format('Y-m')])
            ->with('success', 'Pengajuan lembur berhasil dikirim ke pemberi persetujuan pertama.');
    }

    public function createUsage(Request $request, LemburService $service): Response
    {
        $context = $service->context($request->user());
        $shift = $context['pegawai']?->shift;

        return Inertia::render('absensi/absensi-mobile/LemburUsageCreate', [
            'today' => now()->toDateString(), 'balance' => $service->balance($request->user()),
            'employee' => $context['pegawai'] ? ['name' => $context['pegawai']->nama,
                'position' => $context['jabatan']?->nama, 'unit' => $context['jabatan']?->unitKerja?->nama] : null,
            'shift' => $shift ? ['id' => $shift->id, 'name' => $shift->nama,
                'start_time' => substr($shift->jam_masuk, 0, 5), 'end_time' => substr($shift->jam_keluar, 0, 5)] : null,
            'approvalPreview' => $context['line']?->steps->map(fn ($step) => ['level' => $step->level,
                'name' => $step->user?->name ?? 'Pengguna tidak tersedia'])->values() ?? [],
            'unavailableReason' => $context['unavailable_reason'] ?? ($shift ? null : 'Shift Anda belum diatur. Hubungi administrator.'),
        ]);
    }

    public function storeUsage(StoreLemburUsageRequest $request, LemburService $service): RedirectResponse
    {
        $usage = $service->submitUsage($request->user(), $request->validated());

        return to_route('absensi.mobile.lembur.index', ['month' => $usage->tanggal_lembur->format('Y-m')])
            ->with('success', 'Penggunaan waktu lembur diajukan. Saldo dicadangkan sampai keputusan akhir.');
    }

    public function decide(DecideLemburRequest $request, LemburRequest $lemburRequest, LemburService $service): RedirectResponse
    {
        $lembur = $service->decide($lemburRequest, $request->user(), $request->validated());

        return back()->with('success', match ($lembur->status) {
            'approved' => 'Pengajuan lembur telah disetujui seluruh pemberi persetujuan.',
            'rejected' => 'Pengajuan lembur ditolak. Catatan Anda telah disimpan.',
            default => 'Persetujuan disimpan dan diteruskan ke tingkat berikutnya.',
        });
    }

    public function cancel(Request $request, LemburRequest $lemburRequest, LemburService $service): RedirectResponse
    {
        $service->cancel($lemburRequest, $request->user());

        return back()->with('success', 'Pengajuan lembur dibatalkan.');
    }

    public function attachment(Request $request, LemburRequest $lemburRequest, int $attachment): StreamedResponse
    {
        abort_unless($lemburRequest->canBeViewedBy($request->user()), 403);
        $file = $lemburRequest->attachments[$attachment] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        if ($request->boolean('preview')) {
            $contentType = self::PREVIEW_CONTENT_TYPES[strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))] ?? null;
            abort_unless($contentType, 415, 'Format lampiran ini belum mendukung pratinjau.');

            return Storage::disk('local')->response($file['path'], $file['name'], [
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
            ], 'inline');
        }

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    private function serialize(Request $request, LemburRequest $lembur): array
    {
        return [
            'request_kind' => $lembur->request_kind, 'usage_type' => $lembur->usage_type,
            'usage_start' => $lembur->usage_start_at?->format('d M Y, H:i'), 'usage_end' => $lembur->usage_end_at?->format('d M Y, H:i'),
            'sources' => $lembur->sources->map(fn ($source) => ['id' => $source->id,
                'date' => $source->tanggal_lembur->format('Y-m-d'), 'minutes' => (int) $source->pivot->minutes])->values(),
            'id' => $lembur->id, 'date' => $lembur->tanggal_lembur->format('Y-m-d'), 'name' => $lembur->nama_pegawai,
            'position' => $lembur->nama_jabatan, 'unit' => $lembur->nama_unit, 'shift_name' => $lembur->nama_shift,
            'shift_start' => $lembur->shift_start_at->format('d M Y, H:i'), 'shift_end' => $lembur->shift_end_at->format('d M Y, H:i'),
            'start_time' => ($lembur->usage_start_at ?? $lembur->before_start_at ?? $lembur->after_start_at)->format('H:i'),
            'end_time' => ($lembur->usage_end_at ?? $lembur->after_end_at ?? $lembur->before_end_at)->format('H:i'),
            'before_start' => $lembur->before_start_at?->format('d M Y, H:i'), 'before_end' => $lembur->before_end_at?->format('d M Y, H:i'),
            'after_start' => $lembur->after_start_at?->format('d M Y, H:i'), 'after_end' => $lembur->after_end_at?->format('d M Y, H:i'),
            'before_minutes' => $lembur->before_minutes, 'before_break_minutes' => $lembur->before_break_minutes,
            'after_minutes' => $lembur->after_minutes, 'after_break_minutes' => $lembur->after_break_minutes,
            'duration_minutes' => $lembur->duration_minutes, 'compensation' => $lembur->compensation, 'reason' => $lembur->alasan,
            'status' => $lembur->status, 'current_level' => $lembur->current_level, 'created_at' => $lembur->created_at->format('d M Y, H:i'),
            'can_cancel' => $lembur->canBeCancelledBy($request->user()), 'can_decide' => $lembur->canBeDecidedBy($request->user()),
            'attachments' => collect($lembur->attachments ?? [])->map(fn ($file, $index) => [
                'name' => $file['name'], 'size' => $file['size'],
                'url' => route('absensi.mobile.lembur.attachment', ['lemburRequest' => $lembur->id, 'attachment' => $index], absolute: false),
                'preview_url' => isset(self::PREVIEW_CONTENT_TYPES[strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))])
                    ? route('absensi.mobile.lembur.attachment', ['lemburRequest' => $lembur->id, 'attachment' => $index, 'preview' => 1], absolute: false)
                    : null,
            ])->values(),
            'approvals' => $lembur->approvals->map(fn ($step) => [
                'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
            ])->values(),
        ];
    }
}
