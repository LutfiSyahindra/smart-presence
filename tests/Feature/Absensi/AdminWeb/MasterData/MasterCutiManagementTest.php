<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AdminWeb\MasterData\Cuti\AnnualCutiSpreadsheetService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterCutiManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/master-data/cuti';

    private User $admin;

    private User $approver;

    private Pegawai $employee;

    private Pegawai $other;

    private MasterCuti $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 12)->startOfDay());
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->approver = User::factory()->create();
        $this->approver->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE]);
        $unit = UnitKerja::query()->create(['kode' => 'SIRS', 'nama' => 'SIRS', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'STAFF', 'nama' => 'Staff', 'aktif' => true]);
        $this->employee = $this->employee('001.01', 'Aminah', $position);
        $this->other = $this->employee('001.02', 'Budi', $position);
        // Include a mobile-only user: allocation does not require an admin role or verified email.
        $this->other->user->revokePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->other->user->assignRole('pegawai');
        $this->other->user->forceFill(['email_verified_at' => null])->save();
        $line = ApprovalLine::query()->create(['type' => 'cuti']);
        $line->jabatans()->attach($position->id, ['type' => 'cuti']);
        $line->steps()->create(['user_id' => $this->approver->id, 'level' => 1]);
        $this->annual = MasterCuti::query()->create(['kode' => 'TAHUNAN', 'nama' => 'Cuti Tahunan', 'jenis' => 'tahunan', 'durasi_hari' => 12, 'aktif' => true]);
        $this->actingAs($this->admin);
    }

    private function employee(string $nik, string $name, Jabatan $position): Pegawai
    {
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::CUTI_VIEW, AccessPermission::CUTI_CREATE, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        $employee = Pegawai::query()->create(['nik' => $nik, 'nama' => $name, 'unit_kerja_id' => $position->unit_kerja_id, 'jabatan_id' => $position->id]);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $employee;
    }

    private function allocation(array $overrides = []): array
    {
        return array_replace(['scope' => 'selected', 'pegawai_ids' => [$this->employee->id], 'tahun' => 2026], $overrides);
    }

    private function grant(): void
    {
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation())->assertSessionHasNoErrors();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['master_cuti_id' => $this->annual->id, 'tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-22', 'alasan' => 'Keperluan keluarga'], $overrides);
    }

    private function submit(array $overrides = []): CutiRequest
    {
        $this->actingAs($this->employee->user)->post('/absensi/admin-web/cuti', $this->payload($overrides))->assertSessionHasNoErrors();

        return CutiRequest::query()->latest('id')->firstOrFail();
    }

    private function excel(array $rows, string $format = 'xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Cuti Tahunan');
        $sheet->fromArray([['NIK', 'Nama Pegawai', 'Jumlah Cuti Tahunan']]);
        foreach ($rows as $index => $row) {
            $sheet->setCellValueExplicit('A'.($index + 2), $row[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.($index + 2), $row[1], DataType::TYPE_STRING);
            $sheet->setCellValue('C'.($index + 2), $row[2]);
        }
        ob_start();
        $writer = $format === 'xls' ? new Xls($book) : new Xlsx($book);
        $writer->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('cuti.'.$format, $content);
    }

    public function test_master_page_only_includes_employees_with_accounts_and_shows_year_balance(): void
    {
        Pegawai::query()->create(['nama' => 'Tanpa akun']);
        $this->grant();
        $this->get(self::URL.'?tahun=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/master-data/cuti/Index')->has('employeeOptions', 2)->has('employees.data', 2)
            ->where('overview.employees', 2)->where('overview.annual_assigned', 1)
            ->where('employees.data.0.kuota', 12)->where('employees.data.0.sisa', 12)->where('can.assign', true));
        $this->get(self::URL.'?tahun=2027')->assertOk()->assertInertia(fn (Assert $page) => $page->where('employees.data.0.kuota', null));
    }

    public function test_permissions_protect_each_management_endpoint(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::CUTI_VIEW, AccessPermission::CUTI_CREATE, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        $this->actingAs($user);
        $this->get(self::URL)->assertForbidden();
        $this->post(self::URL, [])->assertForbidden();
        $this->put(self::URL."/{$this->annual->id}", [])->assertForbidden();
        $this->delete(self::URL."/{$this->annual->id}")->assertForbidden();
        $this->post(self::URL."/{$this->annual->id}/allocate", [])->assertForbidden();
        $this->put(self::URL.'/tahunan', [])->assertForbidden();
        $this->post(self::URL.'/import', [])->assertForbidden();
        $this->get(self::URL.'/template')->assertForbidden();
        $user->givePermissionTo(AccessPermission::MASTER_CUTI_VIEW);
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.assign', false)->where('can.create', false));
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 10]))->assertForbidden();
    }

    public function test_types_can_be_created_updated_and_deleted_with_validations(): void
    {
        $data = ['kode' => ' nikah ', 'nama' => 'Cuti Menikah', 'jenis' => null, 'durasi_hari' => 3, 'deskripsi' => null, 'aktif' => true];
        $this->post(self::URL, $data)->assertSessionHasNoErrors();
        $master = MasterCuti::query()->where('kode', 'NIKAH')->sole();
        $this->post(self::URL, $data)->assertSessionHasErrors('kode');
        $this->post(self::URL, array_replace($data, ['kode' => 'TAHUNAN2', 'jenis' => 'tahunan']))->assertSessionHasErrors('jenis');
        $this->post(self::URL, array_replace($data, ['kode' => 'INVALID', 'durasi_hari' => 0]))->assertSessionHasErrors('durasi_hari');
        $this->post(self::URL."/{$master->id}/allocate", $this->allocation())->assertSessionHasNoErrors();
        $this->put(self::URL."/{$master->id}", array_replace($data, ['durasi_hari' => 4]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pegawai_cutis', ['master_cuti_id' => $master->id, 'jumlah_hari' => 4]);
        $this->delete(self::URL."/{$master->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('master_cutis', ['id' => $master->id]);
        $this->assertDatabaseCount('pegawai_cutis', 0);
    }

    public function test_selected_and_all_allocations_are_idempotent_and_preserve_annual_overrides(): void
    {
        $unlinked = Pegawai::query()->create(['nama' => 'Tanpa akun']);
        $this->grant();
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 20]))->assertSessionHasNoErrors();
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation(['scope' => 'all', 'pegawai_ids' => []]))->assertSessionHasNoErrors();
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation(['scope' => 'all', 'pegawai_ids' => []]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pegawai_cutis', 2);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 20]);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->other->id, 'jumlah_hari' => 12]);
        $this->assertDatabaseMissing('pegawai_cutis', ['pegawai_id' => $unlinked->id]);
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation(['pegawai_ids' => [$this->employee->id, $unlinked->id]]))->assertSessionHasErrors('pegawai_ids.1');
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation(['pegawai_ids' => []]))->assertSessionHasErrors('pegawai_ids');
    }

    public function test_annual_quotas_can_be_set_manually_selected_or_all_including_zero_and_separate_years(): void
    {
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 5]))->assertSessionHasNoErrors();
        $this->put(self::URL.'/tahunan', $this->allocation(['scope' => 'all', 'jumlah_hari' => 15]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pegawai_cutis', 2);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'tahun' => 2026, 'jumlah_hari' => 15]);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->other->id, 'tahun' => 2026, 'jumlah_hari' => 15]);
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 0, 'tahun' => 2027]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'tahun' => 2027, 'jumlah_hari' => 0]);
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => -1]))->assertSessionHasErrors('jumlah_hari');
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 1.5]))->assertSessionHasErrors('jumlah_hari');
    }

    public function test_pending_requests_reserve_balance_and_rejection_releases_it(): void
    {
        $this->grant();
        $request = $this->submit();
        $this->assertSame(3, $request->jumlah_hari);
        $this->assertSame('Cuti Tahunan', $request->nama_cuti);
        $this->get('/absensi/admin-web/cuti')->assertOk()->assertInertia(fn (Assert $page) => $page->where('leaveOptions.0.sisa', 9));
        $this->post('/absensi/admin-web/cuti', $this->payload(['tanggal_mulai' => '2026-11-01', 'tanggal_selesai' => '2026-11-10']))->assertSessionHasErrors('tanggal_selesai');
        $this->actingAs($this->approver)->post("/absensi/admin-web/cuti/{$request->id}/decision", ['decision' => 'rejected', 'note' => 'Jadwal penuh'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee->user)->get('/absensi/admin-web/cuti')->assertOk()->assertInertia(fn (Assert $page) => $page->where('leaveOptions.0.sisa', 12));
        $this->submit(['tanggal_mulai' => '2026-11-01', 'tanggal_selesai' => '2026-11-10']);
    }

    public function test_approved_balance_is_visible_and_bulk_reduction_is_atomic(): void
    {
        $this->grant();
        $request = $this->submit();
        $this->actingAs($this->approver)->post("/absensi/admin-web/cuti/{$request->id}/decision", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('employees.data.0.terpakai', 3)->where('employees.data.0.menunggu', 0)->where('employees.data.0.sisa', 9));
        $this->put(self::URL.'/tahunan', $this->allocation(['scope' => 'all', 'jumlah_hari' => 2]))->assertSessionHasErrors('jumlah_hari');
        $this->assertDatabaseCount('pegawai_cutis', 1);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 12]);
    }

    public function test_unassigned_inactive_cross_year_and_unfunded_annual_requests_are_rejected(): void
    {
        $this->actingAs($this->employee->user)->post('/absensi/admin-web/cuti', $this->payload())->assertSessionHasErrors('master_cuti_id');
        $this->actingAs($this->admin);
        $this->grant();
        $this->annual->update(['aktif' => false]);
        $this->post(self::URL."/{$this->annual->id}/allocate", $this->allocation())->assertSessionHasErrors('master_cuti');
        $this->actingAs($this->employee->user)->post('/absensi/admin-web/cuti', $this->payload())->assertSessionHasErrors('master_cuti_id');
        $this->annual->update(['aktif' => true]);
        $this->post('/absensi/admin-web/cuti', $this->payload(['tanggal_mulai' => '2026-12-31', 'tanggal_selesai' => '2027-01-01']))->assertSessionHasErrors('tanggal_selesai');
        $this->post('/absensi/admin-web/cuti', $this->payload(['tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-01-02']))->assertSessionHasErrors('master_cuti_id');
        $this->post('/absensi/admin-web/cuti', $this->payload(['master_cuti_id' => null]))->assertSessionHasErrors('master_cuti_id');
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    public function test_ordinary_duration_is_per_request_and_does_not_spend_annual_balance(): void
    {
        $master = MasterCuti::query()->create(['kode' => 'NIKAH', 'nama' => 'Cuti Menikah', 'durasi_hari' => 3, 'aktif' => true]);
        $this->post(self::URL."/{$master->id}/allocate", $this->allocation())->assertSessionHasNoErrors();
        $this->grant();
        $request = $this->submit(['master_cuti_id' => $master->id]);
        $this->assertFalse($request->is_tahunan);
        $this->assertNull($request->tahun_cuti);
        $this->post('/absensi/admin-web/cuti', $this->payload(['master_cuti_id' => $master->id, 'tanggal_mulai' => '2026-11-01', 'tanggal_selesai' => '2026-11-04']))->assertSessionHasErrors('tanggal_selesai');
        $this->submit(['master_cuti_id' => $master->id, 'tanggal_mulai' => '2026-11-01', 'tanggal_selesai' => '2026-11-03']);
        $this->get('/absensi/admin-web/cuti')->assertOk()->assertInertia(fn (Assert $page) => $page->where('leaveOptions.1.sisa', 12));
    }

    public function test_used_types_keep_snapshots_and_cannot_be_deleted_or_recategorized(): void
    {
        $this->grant();
        $request = $this->submit();
        $this->actingAs($this->admin)->delete(self::URL."/{$this->annual->id}")->assertSessionHasErrors('master_cuti');
        $data = $this->annual->only(['kode', 'nama', 'jenis', 'durasi_hari', 'aktif']);
        $this->put(self::URL."/{$this->annual->id}", array_replace($data, ['jenis' => null]))->assertSessionHasErrors('jenis');
        $this->put(self::URL."/{$this->annual->id}", array_replace($data, ['nama' => 'Tahunan Baru', 'durasi_hari' => 20, 'aktif' => false]))->assertSessionHasNoErrors();
        $this->assertSame('Cuti Tahunan', $request->fresh()->nama_cuti);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 12]);
    }

    public function test_xlsx_and_xls_import_match_nik_preserve_zero_and_replace_only_filled_rows(): void
    {
        $this->grant();
        $this->post(self::URL.'/import', ['tahun' => 2026, 'file' => $this->excel([
            ['001.01', 'Nama referensi', 20], ['001.02', 'Budi', 0],
        ])])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 20]);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->other->id, 'jumlah_hari' => 0]);
        $this->post(self::URL.'/import', ['tahun' => 2026, 'file' => $this->excel([
            ['001.01', 'Aminah', null], ['001.02', 'Budi', 8],
        ], 'xls')])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 20]);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->other->id, 'jumlah_hari' => 8]);
    }

    public function test_invalid_excel_rows_and_duplicates_do_not_partially_update_quotas(): void
    {
        $this->grant();
        foreach ([['UNKNOWN', 'Unknown', 8], ['001.02', 'Budi', -1], ['001.02', 'Budi', '=3+3'], ['001.01', 'Duplikat', 8], ['001.02', 'Budi', 1.5]] as $badRow) {
            $this->post(self::URL.'/import', ['tahun' => 2026, 'file' => $this->excel([['001.01', 'Aminah', 25], $badRow])])->assertSessionHasErrors('file');
            $this->assertDatabaseCount('pegawai_cutis', 1);
            $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 12]);
        }
        $this->post(self::URL.'/import', ['tahun' => 2026, 'file' => $this->excel([['001.01', 'Aminah', null]])])->assertSessionHasErrors('file');
        $this->post(self::URL.'/import', ['tahun' => 2026, 'file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'invalid')])->assertSessionHasErrors('file');
    }

    public function test_excel_cannot_reduce_below_pending_usage_and_rolls_back_all_rows(): void
    {
        $this->grant();
        $this->submit();
        $this->actingAs($this->admin)->post(self::URL.'/import', ['tahun' => 2026, 'file' => $this->excel([
            ['001.02', 'Budi', 15], ['001.01', 'Aminah', 2],
        ])])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('pegawai_cutis', 1);
        $this->assertDatabaseHas('pegawai_cutis', ['pegawai_id' => $this->employee->id, 'jumlah_hari' => 12]);
    }

    public function test_template_is_downloadable_and_round_trips_with_leading_zero_nik(): void
    {
        $this->get(self::URL.'/template')->assertOk()->assertDownload('template-cuti-tahunan.xlsx');
        $book = app(AnnualCutiSpreadsheetService::class)->template();
        $this->assertSame('001.01', $book->getActiveSheet()->getCell('A2')->getValue());
        $book->getActiveSheet()->setCellValue('C2', 7);
        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();
        $items = app(AnnualCutiSpreadsheetService::class)->read(UploadedFile::fake()->createWithContent('template.xlsx', $content));
        $this->assertSame([['pegawai_id' => $this->employee->id, 'jumlah_hari' => 7, 'source_row' => 2]], $items);
    }

    public function test_annual_configuration_requires_annual_master_and_system_permissions_are_registered(): void
    {
        $this->annual->delete();
        $this->put(self::URL.'/tahunan', $this->allocation(['jumlah_hari' => 12]))->assertSessionHasErrors('master_cuti');
        foreach ([AccessPermission::MASTER_CUTI_VIEW, AccessPermission::MASTER_CUTI_CREATE, AccessPermission::MASTER_CUTI_UPDATE, AccessPermission::MASTER_CUTI_DELETE, AccessPermission::MASTER_CUTI_ASSIGN] as $permission) {
            $this->assertTrue(AccessPermission::isSystem($permission));
            $this->assertTrue($this->admin->can($permission));
        }
    }
}
