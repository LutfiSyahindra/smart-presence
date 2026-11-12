<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Pegawai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai\ExportPegawaiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai\ImportPegawaiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai\SendBatchPegawaiInvitationsRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai\StorePegawaiRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai\UpdatePegawaiRequest;
use App\Models\Pegawai;
use App\Services\Absensi\AdminWeb\MasterData\Pegawai\PegawaiService;
use App\Support\AccessPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PegawaiController extends Controller
{
    public function __construct(private readonly PegawaiService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/master-data/pegawai/Index', [
            'pegawais' => $this->service->paginate($request->only('search', 'status', 'unit_kerja')),
            'overview' => $this->service->overview(),
            'unitOptions' => $this->service->formOptions(),
            'filters' => $request->only('search', 'status', 'unit_kerja'),
            'can' => [
                'create' => $request->user()->can(AccessPermission::PEGAWAI_CREATE),
                'update' => $request->user()->can(AccessPermission::PEGAWAI_UPDATE),
                'delete' => $request->user()->can(AccessPermission::PEGAWAI_DELETE),
                'create_user' => $request->user()->can(AccessPermission::USERS_CREATE),
                'cancel_user_invitation' => $request->user()->can(AccessPermission::USERS_DELETE),
                'export' => $request->user()->can(AccessPermission::PEGAWAI_VIEW),
            ],
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $spreadsheet = $this->service->template();

            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'template-import-pegawai.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function store(StorePegawaiRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('absensi.admin-web.master-data.pegawai.index')->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    public function exportOptions(): JsonResponse
    {
        return response()->json(['data' => $this->service->exportOptions()]);
    }

    public function export(ExportPegawaiRequest $request): StreamedResponse
    {
        $spreadsheet = $this->service->export($request->boolean('all'), $request->validated('pegawai_ids', []));

        return response()->streamDownload(function () use ($spreadsheet): void {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'data-pegawai.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function import(ImportPegawaiRequest $request): RedirectResponse
    {
        $result = $this->service->import($request->file('file'), $request->user()->can(AccessPermission::PEGAWAI_UPDATE));
        $message = "{$result['total']} data pegawai berhasil diimpor";
        if ($result['updated'] > 0) {
            $message .= " ({$result['created']} ditambahkan, {$result['updated']} diperbarui)";
        }

        return to_route('absensi.admin-web.master-data.pegawai.index')
            ->with('success', $message.'.');
    }

    public function update(UpdatePegawaiRequest $request, Pegawai $pegawai): RedirectResponse
    {
        $this->service->update($pegawai, $request->validated());

        return to_route('absensi.admin-web.master-data.pegawai.index')->with('success', 'Data pegawai berhasil diperbarui.');
    }

    public function inviteToUser(Pegawai $pegawai): RedirectResponse
    {
        $result = $this->service->inviteToUser($pegawai);
        $message = $result['resent']
            ? "Undangan aktivasi berhasil dikirim ulang ke {$result['user']->email}."
            : "Akun pegawai berhasil dibuat dan undangan aktivasi dikirim ke {$result['user']->email}.";

        return to_route('absensi.admin-web.master-data.pegawai.index')->with('success', $message);
    }

    public function cancelUserInvitation(Request $request, Pegawai $pegawai): RedirectResponse
    {
        $this->service->cancelUserInvitation($pegawai, $request->user());

        return to_route('absensi.admin-web.master-data.pegawai.index')
            ->with('success', 'Aktivasi akun pegawai berhasil dibatalkan. Data pegawai tetap tersimpan.');
    }

    public function inviteManyToUsers(SendBatchPegawaiInvitationsRequest $request): RedirectResponse
    {
        $result = $this->service->inviteManyToUsers($request->validated('pegawai_ids'));

        $message = match (true) {
            $result['created'] > 0 && $result['resent'] > 0 => "{$result['total']} email aktivasi berhasil dikirim ({$result['created']} akun baru dan {$result['resent']} undangan ulang).",
            $result['created'] > 0 => "{$result['total']} akun pegawai berhasil dibuat dan email aktivasi dikirim.",
            default => "{$result['total']} email aktivasi berhasil dikirim ulang.",
        };

        return to_route('absensi.admin-web.master-data.pegawai.index')->with('success', $message);
    }

    public function destroy(Pegawai $pegawai): RedirectResponse
    {
        $this->service->delete($pegawai);

        return to_route('absensi.admin-web.master-data.pegawai.index')->with('success', 'Data pegawai berhasil dihapus.');
    }
}
