<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Jabatan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\StoreJabatanRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan\UpdateJabatanRequest;
use App\Models\Jabatan;
use App\Services\Absensi\AdminWeb\MasterData\Jabatan\JabatanService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JabatanController extends Controller
{
    public function __construct(private readonly JabatanService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/master-data/jabatan/Index', [
            'jabatans' => $this->service->paginate($request->only('search', 'status')),
            'overview' => $this->service->overview(),
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
            $count = $this->service->createMany($data['jabatans'])->count();

            return to_route('absensi.admin-web.master-data.jabatan.index')
                ->with('success', "{$count} jabatan berhasil ditambahkan.");
        }

        $this->service->create($data);

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil ditambahkan.');
    }

    public function update(UpdateJabatanRequest $request, Jabatan $jabatan): RedirectResponse
    {
        $this->service->update($jabatan, $request->validated());

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil diperbarui.');
    }

    public function destroy(Jabatan $jabatan): RedirectResponse
    {
        $this->service->delete($jabatan);

        return to_route('absensi.admin-web.master-data.jabatan.index')->with('success', 'Jabatan berhasil dihapus.');
    }
}
