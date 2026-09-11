<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\StoreShiftRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\UpdateShiftRequest;
use App\Models\Shift;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/master-data/shift/Index', [
            'shifts' => $this->service->paginate($request->only('search')),
            'overview' => $this->service->overview(),
            'filters' => $request->only('search'),
            'can' => [
                'create' => $request->user()->can(AccessPermission::SHIFT_CREATE),
                'update' => $request->user()->can(AccessPermission::SHIFT_UPDATE),
                'delete' => $request->user()->can(AccessPermission::SHIFT_DELETE),
            ],
        ]);
    }

    public function store(StoreShiftRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['shifts'])) {
            $count = $this->service->createMany($data['shifts'])->count();

            return to_route('absensi.admin-web.master-data.shift.index')
                ->with('success', "{$count} shift berhasil ditambahkan.");
        }

        $this->service->create($data);

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil ditambahkan.');
    }

    public function update(UpdateShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->service->update($shift, $request->validated());

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil diperbarui.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $this->service->delete($shift);

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil dihapus.');
    }
}
