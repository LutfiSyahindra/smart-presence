<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\AssignShiftRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\BulkShiftRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\UpdateShiftScheduleRequest;
use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentService;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentSpreadsheetService;
use App\Support\AccessPermission;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'bulkOptions' => $request->user()->can(AccessPermission::SHIFT_UPDATE) ? [
                'month' => now()->format('Y-m'),
                'employees' => Pegawai::query()->orderBy('nama')
                    ->get(['id', 'nik', 'nama', 'unit_kerja_id', 'jabatan_id']),
                'units' => UnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
                'positions' => Jabatan::query()->orderBy('nama')->get(['id', 'nama', 'unit_kerja_id']),
            ] : null,
            'can' => [
                'assign' => $request->user()->can(AccessPermission::SHIFT_UPDATE),
            ],
        ]);
    }

    public function template(BulkShiftRequest $request, ShiftAssignmentSpreadsheetService $spreadsheets): StreamedResponse
    {
        $data = $request->validated();
        $book = $spreadsheets->template($data);

        return response()->streamDownload(function () use ($book) {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'template-assign-shift-'.$data['date_from'].'_'.$data['date_to'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (isset($data['month'], $data['date']) && ! str_starts_with($data['date'], $data['month'].'-')) {
            throw ValidationException::withMessages(['date' => 'Tanggal harus berada dalam bulan yang dipilih.']);
        }

        return response()->json($this->service->calendar($data['month'] ?? null, $data['date'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function schedule(Request $request, Pegawai $pegawai): JsonResponse
    {
        abort_unless($pegawai->user()->exists(), 404);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return response()->json($this->service->schedule($pegawai, $data['month'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateSchedule(UpdateShiftScheduleRequest $request, Pegawai $pegawai): JsonResponse
    {
        $data = $request->validated();
        $this->service->updateSchedule($pegawai, $data['date'], (int) $data['shift_id'], (int) $request->user()->id);

        return response()->json([
            'message' => 'Jadwal shift pada tanggal '.Carbon::parse($data['date'])->format('d/m/Y').' berhasil diperbarui.',
            'schedule' => $this->service->schedule($pegawai->fresh(), substr($data['date'], 0, 7)),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function import(BulkShiftRequest $request, ShiftAssignmentSpreadsheetService $spreadsheets): RedirectResponse
    {
        $result = $spreadsheets->import($request->file('file'), $request->validated(), (int) $request->user()->id);

        return back()->with('success', "{$result['schedules']} jadwal shift untuk {$result['employees']} pegawai berhasil diimpor.");
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
