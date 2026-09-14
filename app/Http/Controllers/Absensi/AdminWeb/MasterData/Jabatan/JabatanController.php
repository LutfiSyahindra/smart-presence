<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Jabatan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\StoreJabatanRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\StoreUnitKerjaRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\UpdateJabatanRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\UpdateUnitKerjaRequest;
use App\Models\Jabatan;
use App\Models\UnitKerja;
use App\Services\Absensi\AdminWeb\MasterData\Jabatan\UnitJabatanService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JabatanController extends Controller
{
    public function __construct(private readonly UnitJabatanService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/master-data/jabatan/Index', [
            'units' => $this->service->paginateUnits($request->only('search', 'status')),
            'overview' => $this->service->overview(),
            'unitOptions' => $this->service->unitOptions(),
            'filters' => $request->only('search', 'status'),
            'can' => [
                'create' => $request->user()->can(AccessPermission::JABATAN_CREATE),
                'update' => $request->user()->can(AccessPermission::JABATAN_UPDATE),
                'delete' => $request->user()->can(AccessPermission::JABATAN_DELETE),
            ],
        ]);
    }

    public function store(StoreJabatanRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['jabatans'])) {
            $count = $this->service->createManyJabatans($data['unit_kerja_id'], $data['jabatans']);

            return to_route('absensi.admin-web.master-data.jabatan.index')
                ->with('success', "{$count} jabatan berhasil ditambahkan.");
        }

        $this->service->createJabatan($data);

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil ditambahkan.');
    }

    public function update(UpdateJabatanRequest $request, Jabatan $jabatan): RedirectResponse
    {
        $this->service->updateJabatan($jabatan, $request->validated());

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil diperbarui.');
    }

    public function destroy(Jabatan $jabatan): RedirectResponse
    {
        $this->service->deleteJabatan($jabatan);

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil dihapus.');
    }

    public function storeUnit(StoreUnitKerjaRequest $request): RedirectResponse
    {
        $unit = $this->service->createUnit($request->validated());

        return to_route('absensi.admin-web.master-data.jabatan.index')
            ->with('success', "Unit {$unit->nama} beserta {$unit->jabatans->count()} jabatan berhasil ditambahkan.");
    }

    public function updateUnit(UpdateUnitKerjaRequest $request, UnitKerja $unitKerja): RedirectResponse
    {
        $this->service->updateUnit($unitKerja, $request->validated());

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Unit kerja berhasil diperbarui.');
    }

    public function destroyUnit(UnitKerja $unitKerja): RedirectResponse
    {
        $this->service->deleteUnit($unitKerja);

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Unit kerja beserta seluruh jabatannya berhasil dihapus.');
    }
}
