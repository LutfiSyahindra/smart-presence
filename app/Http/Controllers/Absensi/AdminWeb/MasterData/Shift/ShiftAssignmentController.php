<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\AssignShiftRequest;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftAssignmentController extends Controller
{
    public function __construct(private readonly ShiftAssignmentService $service) {}

    public function index(Request $request): Response
    {
        $filters = $this->service->normalizeFilters($request->only('search', 'status', 'shift_id'));

        return Inertia::render('absensi/admin-web/master-data/shift/Assign', [
            'assignments' => $this->service->paginate($filters),
            'overview' => $this->service->overview(),
            'shifts' => $this->service->shiftOptions(),
            'filters' => $filters,
            'can' => [
                'assign' => $request->user()->can(AccessPermission::SHIFT_UPDATE),
            ],
        ]);
    }

    public function update(AssignShiftRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $count = $this->service->assign(
            $data['pegawai_ids'],
            $data['shift_id'] ?? null,
            (int) $request->user()->id,
        );

        $message = ($data['shift_id'] ?? null) === null
            ? "Shift {$count} pegawai berhasil dikosongkan."
            : "Shift {$count} pegawai berhasil diperbarui.";

        return back()->with('success', $message);
    }
}
