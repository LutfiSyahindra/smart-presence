<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Cuti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti\AllocateCutiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti\ImportAnnualCutiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti\SaveMasterCutiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti\SetAnnualCutiRequest;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Services\Absensi\AdminWeb\MasterData\Cuti\AnnualCutiSpreadsheetService;
use App\Services\Absensi\AdminWeb\MasterData\Cuti\MasterCutiService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterCutiController extends Controller
{
    public function __construct(private readonly MasterCutiService $service, private readonly AnnualCutiSpreadsheetService $excel) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $tahun = (int) ($filters['tahun'] ?? now()->year);
        $search = trim($filters['search'] ?? '');
        $annual = MasterCuti::query()->where('jenis', 'tahunan')->first();
        $query = Pegawai::query()->whereHas('user')->with(['user', 'unitKerja', 'jabatan'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('nama', 'like', "%{$search}%")->orWhere('nik', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($query) => $query->where('email', 'like', "%{$search}%"))));
        $employees = $query->orderBy('nama')->orderBy('id')->paginate(15)->withQueryString();
        $ids = $employees->getCollection()->modelKeys();
        $allocations = PegawaiCuti::query()->with('masterCuti')->whereIn('pegawai_id', $ids)
            ->whereIn('tahun', [0, $tahun])->get()->groupBy('pegawai_id');
        $usage = $annual ? $this->service->usage($annual->id, $tahun, $ids) : [];

        return Inertia::render('absensi/admin-web/master-data/cuti/Index', [
            'masters' => MasterCuti::query()->withCount(['allocations as penerima_count' => fn ($query) => $query->whereIn('tahun', [0, $tahun])])
                ->orderBy('nama')->get(),
            'employees' => $employees->through(function ($pegawai) use ($allocations, $usage, $annual) {
                $items = $allocations->get($pegawai->id, collect());
                $balance = $annual ? $items->firstWhere('master_cuti_id', $annual->id) : null;
                $used = $usage[$pegawai->id] ?? ['terpakai' => 0, 'menunggu' => 0];

                return [
                    'id' => $pegawai->id, 'nik' => $pegawai->nik, 'nama' => $pegawai->nama, 'email' => $pegawai->user->email,
                    'unit' => $pegawai->unitKerja?->nama, 'jabatan' => $pegawai->jabatan?->nama,
                    'cutis' => $items->map(fn ($item) => ['id' => $item->master_cuti_id, 'nama' => $item->masterCuti->nama])->values(),
                    'kuota' => $balance?->jumlah_hari, 'terpakai' => $used['terpakai'], 'menunggu' => $used['menunggu'],
                    'sisa' => $balance ? max(0, $balance->jumlah_hari - $used['terpakai'] - $used['menunggu']) : null,
                ];
            }),
            'employeeOptions' => Pegawai::query()->whereHas('user')->with('unitKerja')->orderBy('nama')->get()->map(fn ($pegawai) => [
                'value' => $pegawai->id, 'label' => $pegawai->nama,
                'description' => implode(' · ', array_filter([$pegawai->nik, $pegawai->unitKerja?->nama])),
            ]),
            'filters' => ['tahun' => $tahun, 'search' => $search],
            'overview' => ['employees' => Pegawai::query()->whereHas('user')->count(), 'types' => MasterCuti::query()->count(),
                'annual_assigned' => $annual ? $annual->allocations()->where('tahun', $tahun)->whereHas('pegawai.user')->count() : 0],
            'annualId' => $annual?->id,
            'can' => [
                'create' => $request->user()->can(AccessPermission::MASTER_CUTI_CREATE),
                'update' => $request->user()->can(AccessPermission::MASTER_CUTI_UPDATE),
                'delete' => $request->user()->can(AccessPermission::MASTER_CUTI_DELETE),
                'assign' => $request->user()->can(AccessPermission::MASTER_CUTI_ASSIGN),
            ],
        ]);
    }

    public function store(SaveMasterCutiRequest $request): RedirectResponse
    {
        $this->service->save($request->validated());

        return back()->with('success', 'Jenis cuti berhasil ditambahkan.');
    }

    public function update(SaveMasterCutiRequest $request, MasterCuti $masterCuti): RedirectResponse
    {
        $this->service->save($request->validated(), $masterCuti);

        return back()->with('success', 'Jenis cuti diperbarui. Kuota tahunan yang sudah diberikan tetap dipertahankan.');
    }

    public function destroy(MasterCuti $masterCuti): RedirectResponse
    {
        $this->service->delete($masterCuti);

        return back()->with('success', 'Jenis cuti dan alokasinya berhasil dihapus.');
    }

    public function allocate(AllocateCutiRequest $request, MasterCuti $masterCuti): RedirectResponse
    {
        $count = $this->service->allocate($masterCuti, $request->validated(), $request->user()->id);

        return back()->with('success', "Cuti diberikan kepada {$count} pegawai. Kuota tahunan yang sudah ada tetap dipertahankan.");
    }

    public function setAnnual(SetAnnualCutiRequest $request): RedirectResponse
    {
        $count = $this->service->setAnnual($request->validated(), $request->user()->id);

        return back()->with('success', "Kuota cuti tahunan {$count} pegawai berhasil diatur.");
    }

    public function import(ImportAnnualCutiRequest $request): RedirectResponse
    {
        $count = $this->service->importAnnual((int) $request->validated('tahun'), $this->excel->read($request->file('file')), $request->user()->id);

        return back()->with('success', "Kuota cuti tahunan {$count} pegawai berhasil diimpor.");
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $book = $this->excel->template();
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'template-cuti-tahunan.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }
}
