<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AbsensiMobile\StorePerubahanShiftRequest;
use App\Http\Requests\Absensi\AbsensiMobile\DecidePerubahanShiftRequest;
use App\Models\PerubahanShiftRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\PerubahanShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PerubahanShiftController extends Controller
{
    public function index(Request $request, PerubahanShiftService $service, ?PerubahanShiftRequest $perubahanShiftRequest = null): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2100-12'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'scope' => ['nullable', Rule::in(['mine', 'delegation'])],
        ]);
        $user = $request->user();
        if ($perubahanShiftRequest?->exists) {
            abort_unless($perubahanShiftRequest->canBeViewedBy($user), 403);
        }
        $scope = $filters['scope'] ?? ($perubahanShiftRequest?->exists && (int) $perubahanShiftRequest->user_id !== (int) $user->id ? 'delegation' : 'mine');
        $month = $filters['month'] ?? ($perubahanShiftRequest?->tanggal_perubahan?->format('Y-m') ?? now()->format('Y-m'));
        $allPeriods = $scope === 'delegation' && empty($filters['month']) && ! $perubahanShiftRequest?->exists;
        $period = CarbonImmutable::createFromFormat('!Y-m', $month);
        $query = $scope === 'delegation' ? PerubahanShiftRequest::query()->awaitingApprovalBy($user)
            : PerubahanShiftRequest::query()->where('user_id', $user->id);
        $query->when(! $allPeriods, fn ($query) => $query->whereDate('tanggal_perubahan', '>=', $period)
            ->whereDate('tanggal_perubahan', '<=', $period->endOfMonth()));
        $context = $service->context($user);

        return Inertia::render('absensi/absensi-mobile/PerubahanShift', [
            'month' => $month, 'today' => now()->toDateString(), 'status' => $filters['status'] ?? '',
            'scope' => $scope, 'allPeriods' => $allPeriods,
            'approvalCount' => PerubahanShiftRequest::query()->awaitingApprovalBy($user)->count(),
            'initialRequest' => $perubahanShiftRequest?->exists ? $this->serialize($perubahanShiftRequest, $user) : null,
            'summary' => ['count' => (clone $query)->count(), 'pending' => (clone $query)->where('status', 'pending')->count()],
            'requests' => $query->with('approvals')->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->latest('tanggal_perubahan')->latest('id')->paginate(10)
                ->withPath(route('absensi.mobile.perubahan-shift.index', absolute: false))
                ->appends(['scope' => $scope, 'month' => $allPeriods ? null : $month, 'status' => $filters['status'] ?? null])
                ->through(fn ($item) => $this->serialize($item, $user)),
            'employee' => $context['pegawai'] ? ['name' => $context['pegawai']->nama,
                'position' => $context['jabatan']?->nama, 'unit' => $context['jabatan']?->unitKerja?->nama] : null,
            'currentShift' => $context['pegawai']?->shift ? $service::shiftSnapshot($context['pegawai']->shift) : null,
            'shifts' => Shift::query()->orderBy('nama')->get()->map(fn ($shift) => $service::shiftSnapshot($shift)),
            'approvalPreview' => $context['line']?->steps->map(fn ($step) => ['level' => $step->level, 'name' => $step->user?->name ?? 'Pengguna tidak tersedia']) ?? [],
            'unavailableReason' => $context['unavailable_reason'],
        ]);
    }

    public function store(StorePerubahanShiftRequest $request, PerubahanShiftService $service): RedirectResponse
    {
        $item = $service->submit($request->user(), $request->validated());

        return to_route('absensi.mobile.perubahan-shift.index', ['month' => $item->tanggal_perubahan->format('Y-m')])
            ->with('success', 'Pengajuan perubahan shift berhasil disimpan.');
    }

    public function decide(DecidePerubahanShiftRequest $request, PerubahanShiftRequest $perubahanShiftRequest, PerubahanShiftService $service): RedirectResponse
    {
        $service->decide($perubahanShiftRequest, $request->user(), $request->validated());

        return back()->with('success', 'Keputusan pengajuan perubahan shift berhasil disimpan.');
    }

    public function cancel(Request $request, PerubahanShiftRequest $perubahanShiftRequest, PerubahanShiftService $service): RedirectResponse
    {
        $service->cancel($perubahanShiftRequest, $request->user());

        return to_route('absensi.mobile.perubahan-shift.index', ['month' => $perubahanShiftRequest->tanggal_perubahan->format('Y-m')])
            ->with('success', 'Pengajuan perubahan shift berhasil dibatalkan.');
    }

    private function serialize(PerubahanShiftRequest $item, User $user): array
    {
        return [
            'id' => $item->id, 'name' => $item->nama_pegawai, 'date' => $item->tanggal_perubahan->toDateString(), 'status' => $item->status,
            'original_shift' => $item->original_shift, 'requested_shift' => $item->requested_shift, 'reason' => $item->alasan,
            'created_at' => $item->created_at->toIso8601String(), 'current_level' => $item->current_level,
            'can_cancel' => $item->canBeCancelledBy($user), 'cancelled_at' => $item->cancelled_at?->toIso8601String(),
            'can_decide' => $item->canBeDecidedBy($user), 'decided_at' => $item->decided_at?->toIso8601String(),
            'approvals' => $item->approvals->map(fn ($step) => [
                'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status, 'note' => $step->note,
                'decided_at' => $step->decided_at?->toIso8601String(),
            ]),
        ];
    }
}
