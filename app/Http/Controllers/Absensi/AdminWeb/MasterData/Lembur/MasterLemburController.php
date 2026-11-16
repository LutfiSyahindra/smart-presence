<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Lembur;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur\ImportLemburBalanceRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur\LemburBalanceSelectionRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur\SetLemburBalanceRequest;
use App\Models\LemburBalanceAdjustment;
use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\AdminWeb\MasterData\Lembur\LemburBalanceService;
use App\Services\Absensi\AdminWeb\MasterData\Lembur\LemburBalanceSpreadsheetService;
use App\Support\AccessPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterLemburController extends Controller
{
    public function index(Request $request, LemburService $service): Response
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer', 'exists:unit_kerjas,id'],
            'position_id' => ['nullable', 'integer', 'exists:jabatans,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($input['search'] ?? '');
        $query = Pegawai::query()->with(['unitKerja', 'jabatan'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('nama', 'like', "%{$search}%")->orWhere('nik', 'like', "%{$search}%")))
            ->when($input['unit_id'] ?? null, fn (Builder $query, $unit) => $query->where('unit_kerja_id', $unit))
            ->when($input['position_id'] ?? null, fn (Builder $query, $position) => $query->where('jabatan_id', $position));
        $employees = $query->orderBy('nama')->orderBy('id')->paginate(15)->withQueryString();
        $ids = $employees->getCollection()->modelKeys();
        $balances = $service->balances($ids);
        $last = LemburBalanceAdjustment::query()->with('createdBy')->whereIn('id', LemburBalanceAdjustment::query()
            ->whereIn('pegawai_id', $ids)->selectRaw('MAX(id)')->groupBy('pegawai_id'))->get()->keyBy('pegawai_id');

        return Inertia::render('absensi/admin-web/master-data/lembur/Index', [
            'employees' => $employees->through(function ($employee) use ($balances, $last) {
                $adjustment = $last->get($employee->id);

                return ['id' => $employee->id, 'name' => $employee->nama, 'nik' => $employee->nik,
                    'unit' => $employee->unitKerja?->nama, 'position' => $employee->jabatan?->nama,
                    ...$balances[$employee->id], 'last_adjustment' => $adjustment ? [
                        'at' => $adjustment->created_at->format('d M Y, H:i'), 'by' => $adjustment->createdBy?->name,
                        'source' => $adjustment->source, 'note' => $adjustment->note,
                        'before_minutes' => $adjustment->before_minutes, 'after_minutes' => $adjustment->after_minutes,
                    ] : null];
            }),
            'units' => UnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
            'positions' => Jabatan::query()->orderBy('nama')->get(['id', 'nama', 'unit_kerja_id']),
            'employeeOptions' => Inertia::optional(fn () => $request->user()->can(AccessPermission::MASTER_LEMBUR_ASSIGN)
                ? Pegawai::query()->whereNotNull('nik')->where('nik', '!=', '')->orderBy('nama')->orderBy('id')
                    ->get(['id', 'nama', 'nik', 'unit_kerja_id', 'jabatan_id']) : []),
            'filters' => ['search' => $search, 'unit_id' => isset($input['unit_id']) ? (int) $input['unit_id'] : '',
                'position_id' => isset($input['position_id']) ? (int) $input['position_id'] : ''],
            'maxMinutes' => LemburBalanceService::MAX_MINUTES,
            'can' => ['assign' => $request->user()->can(AccessPermission::MASTER_LEMBUR_ASSIGN)],
        ]);
    }

    public function setBalance(SetLemburBalanceRequest $request, LemburBalanceService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->set([['pegawai_id' => (int) $data['pegawai_id'], 'minutes' => (int) $data['minutes']]],
            $request->user()->id, 'manual', $data['note']);

        return back()->with('success', 'Saldo tersedia pegawai berhasil diatur.');
    }

    public function import(ImportLemburBalanceRequest $request, LemburBalanceService $service, LemburBalanceSpreadsheetService $excel): RedirectResponse
    {
        $data = $request->validated();
        $items = $excel->read($request->file('file'), $data);
        $count = $service->set($items, $request->user()->id, 'excel', trim($request->validated('note') ?? '') ?: 'Impor saldo lembur Excel');

        return back()->with('success', "Saldo tersedia {$count} pegawai berhasil diatur melalui Excel.");
    }

    public function template(LemburBalanceSelectionRequest $request, LemburBalanceSpreadsheetService $excel): StreamedResponse
    {
        // Build before streaming so selection errors can be returned as validation errors.
        $book = $excel->template($request->validated());

        return response()->streamDownload(function () use ($book) {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'template-saldo-lembur.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
    }
}
