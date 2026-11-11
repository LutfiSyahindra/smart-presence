<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use App\Services\Absensi\AdminWeb\MasterData\Pegawai\PegawaiSpreadsheetService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\UnitJabatanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PegawaiManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const PROFILE_HEADERS = [
        'NIK', 'Nama Pegawai', 'Profesi', 'Unit Kerja', 'Jabatan', 'Jenis Kelamin', 'Status Pegawai',
        'Tanggal Mulai Bekerja', 'Tanggal Lahir', 'Nomor Telepon', 'Email',
    ];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([AuthorizationSeeder::class, UnitJabatanSeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_access_is_protected_by_authentication_and_permission(): void
    {
        $this->get('/absensi/admin-web/master-data/pegawai')->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/master-data/pegawai')->assertForbidden();

        $user->givePermissionTo([
            AccessPermission::ADMIN_ACCESS,
            AccessPermission::PEGAWAI_VIEW,
        ]);

        $this->get('/absensi/admin-web/master-data/pegawai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/pegawai/Index')
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false)
                ->where('can.create_user', false));
    }

    public function test_super_admin_can_download_the_official_excel_template(): void
    {
        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/pegawai/template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('template-import-pegawai.xlsx');
    }

    public function test_export_options_include_employees_beyond_the_directory_page_and_filters(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            Pegawai::query()->create(['nik' => "OPT.{$index}", 'nama' => sprintf('Pegawai %02d', $index)]);
        }

        $this->actingAs($this->admin)
            ->getJson('/absensi/admin-web/master-data/pegawai/export-options?search=tidak-ada&page=2&status=Tetap')
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.nama', 'Pegawai 01')
            ->assertJsonPath('data.11.nama', 'Pegawai 12')
            ->assertJsonStructure(['data' => [['id', 'nik', 'nama', 'unit_kerja']]]);
    }

    public function test_export_contains_only_selected_employees_with_safe_text_and_complete_profiles(): void
    {
        $organization = $this->organization();
        Pegawai::query()->create(['nama' => 'Ari Tidak Dipilih']);
        $budi = Pegawai::query()->create([
            ...$organization,
            'nik' => '000123',
            'nama' => 'Budi',
            'profesi' => '=1+1',
            'jenis_kelamin' => 'Laki-laki',
            'status_pegawai' => 'Tetap',
            'tanggal_mulai_bekerja' => '2026-01-02',
            'tanggal_lahir' => '1990-03-04',
            'nomor_telepon' => '081234567890',
            'email' => 'budi@rsarsy.co.id',
        ]);
        $citra = Pegawai::query()->create(['nama' => 'Citra']);

        $response = $this->actingAs($this->admin)
            ->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => false, 'pegawai_ids' => [$citra->id, $budi->id]])
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('data-pegawai.xlsx');

        $spreadsheet = $this->readExport($response);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame(1, $spreadsheet->getSheetCount());
            $this->assertSame('Email', $sheet->getCell('K1')->getValue());
            $this->assertSame([
                '000123', 'Budi', '=1+1', 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap',
            ], $sheet->rangeToArray('A2:G2')[0]);
            $this->assertSame('02/01/2026', $sheet->getCell('H2')->getFormattedValue());
            $this->assertSame('04/03/1990', $sheet->getCell('I2')->getFormattedValue());
            $this->assertSame('081234567890', $sheet->getCell('J2')->getValue());
            $this->assertSame('budi@rsarsy.co.id', $sheet->getCell('K2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
            $this->assertSame('Citra', $sheet->getCell('B3')->getValue());
            $this->assertNull($sheet->getCell('H3')->getValue());
            $this->assertNull($sheet->getCell('B4')->getValue());
            $this->assertSame('A1:K3', $sheet->getAutoFilter()->getRange());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_select_all_exports_the_entire_directory_across_pages(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            Pegawai::query()->create(['nama' => sprintf('Pegawai %02d', $index)]);
        }

        $response = $this->actingAs($this->admin)
            ->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])
            ->assertOk();

        $spreadsheet = $this->readExport($response);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('Pegawai 01', $sheet->getCell('B2')->getValue());
            $this->assertSame('Pegawai 12', $sheet->getCell('B13')->getValue());
            $this->assertNull($sheet->getCell('B14')->getValue());
            $this->assertSame('A1:K13', $sheet->getAutoFilter()->getRange());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_export_rejects_empty_duplicate_or_missing_employee_selections(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('pegawai_ids');

        $pegawai = Pegawai::query()->create(['nama' => 'Pegawai Validasi']);

        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['pegawai_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('pegawai_ids');
        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['pegawai_ids' => [$pegawai->id, $pegawai->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('pegawai_ids.1');
        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['pegawai_ids' => [$pegawai->id + 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('pegawai_ids.0');
    }

    public function test_export_requires_employee_view_permission(): void
    {
        $this->getJson('/absensi/admin-web/master-data/pegawai/export-options')->assertUnauthorized();
        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])->assertUnauthorized();

        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $pegawai = Pegawai::query()->create(['nama' => 'Pegawai Akses']);

        $this->actingAs($user)->getJson('/absensi/admin-web/master-data/pegawai/export-options')->assertForbidden();
        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])->assertForbidden();

        $user->givePermissionTo(AccessPermission::PEGAWAI_VIEW);
        $this->get('/absensi/admin-web/master-data/pegawai')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('can.export', true));
        $this->getJson('/absensi/admin-web/master-data/pegawai/export-options')->assertOk()->assertJsonPath('data.0.id', $pegawai->id);
        $this->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])->assertOk()->assertDownload('data-pegawai.xlsx');
    }

    public function test_excel_template_contains_the_required_columns_and_safe_contact_formats(): void
    {
        $spreadsheet = app(PegawaiSpreadsheetService::class)->template();
        $sheet = $spreadsheet->getSheetByName('Data Pegawai');

        $this->assertNotNull($sheet);
        $this->assertSame([
            'NIK',
            'Nama Pegawai',
            'Profesi',
            'Unit Kerja',
            'Jabatan',
            'Jenis Kelamin',
            'Status Pegawai',
            'Tanggal Mulai Bekerja',
            'Tanggal Lahir',
            'Nomor Telepon',
            'Email',
        ], $sheet->rangeToArray('A1:K1')[0]);
        $this->assertSame('@', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('J2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0F766E', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertSame(Fill::FILL_SOLID, $sheet->getStyle('A1')->getFill()->getFillType());
        $this->assertNotNull($spreadsheet->getSheetByName('Petunjuk'));

        $spreadsheet->disconnectWorksheets();
    }

    public function test_super_admin_can_import_valid_pegawai_rows_from_excel(): void
    {
        $file = $this->excelUpload([
            [' Siti   Aminah ', '17/08/1995', '0812-3456-7890'],
            ['Budi Santoso', ExcelDate::PHPToExcel(new \DateTimeImmutable('1988-02-03')), '+62 813 9876 5432'],
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', '2 data pegawai berhasil diimpor.');

        $this->assertDatabaseHas('pegawais', [
            'nama' => 'Siti Aminah',
            'tanggal_lahir' => '1995-08-17 00:00:00',
            'nomor_telepon' => '081234567890',
        ]);
        $this->assertDatabaseHas('pegawais', [
            'nama' => 'Budi Santoso',
            'tanggal_lahir' => '1988-02-03 00:00:00',
            'nomor_telepon' => '081398765432',
        ]);
        $this->assertNull(Pegawai::query()->where('nama', 'Siti Aminah')->firstOrFail()->user_id);
    }

    public function test_super_admin_can_import_the_workforce_layout_with_organization_and_employment_data(): void
    {
        $file = $this->excelUpload([
            [1, '157.101.019', 'dr. Arif Banar Rizali, Sp. B', 'Dokter Spesialis Bedah Umum', 'Direksi', 'Direktur', 'Laki-laki', 'Tetap', '07/10/2019', '081211111111', 'arif@rsarsy.co.id'],
            [2, '168.201.020', 'Siti Nur Jannah, S.Kep.,Ns', 'Ners', 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Perempuan', 'Tidak Tetap', '01/05/2020', '+62 812 2222 2222', ' SITI@RSARSY.CO.ID '],
        ], ['No.', 'NIK', 'Nama', 'Profesi', 'Unit Kerja', 'Jabatan', 'Jenis Kelamin', 'Status Karyawan', 'Tanggal Mulai Bekerja', 'Nomor Telepon', 'Email']);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', '2 data pegawai berhasil diimpor.');

        $pegawai = Pegawai::query()->where('nik', '168.201.020')->firstOrFail();
        $this->assertSame('Unit Rawat Jalan', $pegawai->unitKerja->nama);
        $this->assertSame('Staff Unit Rawat Jalan', $pegawai->jabatan->nama);
        $this->assertSame('Perempuan', $pegawai->jenis_kelamin);
        $this->assertSame('Tidak Tetap', $pegawai->status_pegawai);
        $this->assertSame('2020-05-01', $pegawai->tanggal_mulai_bekerja->format('Y-m-d'));
        $this->assertSame('081222222222', $pegawai->nomor_telepon);
        $this->assertSame('siti@rsarsy.co.id', $pegawai->email);
        $this->assertNull($pegawai->user_id);
    }

    public function test_import_updates_matching_niks_and_adds_new_employees_without_duplicates_on_reimport(): void
    {
        $account = User::factory()->create(['name' => 'Nama Awal', 'email' => 'akun.impor@rsarsy.co.id']);
        $existing = Pegawai::query()->create([
            'nik' => 'IMP.001', 'nama' => 'Nama Awal', 'email' => $account->email, 'nomor_telepon' => '081211111111',
        ]);
        $existing->forceFill(['user_id' => $account->id])->save();
        $file = $this->excelUpload([
            ['IMP.001', 'Nama Diperbarui', 'Ners', 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Perempuan', 'Tetap', '01/01/2026', '02/03/1994', '081222222222', $account->email],
            ['IMP.002', 'Nama Diperbarui', 'Ners', 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Perempuan', 'Tidak Tetap', '01/02/2026', null, '081233333333', 'baru.impor@rsarsy.co.id'],
        ], self::PROFILE_HEADERS);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 data pegawai berhasil diimpor (1 ditambahkan, 1 diperbarui).');

        $existing->refresh();
        $this->assertDatabaseCount('pegawais', 2);
        $this->assertSame('Nama Diperbarui', $existing->nama);
        $this->assertSame('Ners', $existing->profesi);
        $this->assertSame('Unit Rawat Jalan', $existing->unitKerja->nama);
        $this->assertSame('Staff Unit Rawat Jalan', $existing->jabatan->nama);
        $this->assertSame('Tetap', $existing->status_pegawai);
        $this->assertSame('Perempuan', $existing->jenis_kelamin);
        $this->assertSame('2026-01-01', $existing->tanggal_mulai_bekerja->format('Y-m-d'));
        $this->assertSame('1994-03-02', $existing->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('081222222222', $existing->nomor_telepon);
        $this->assertSame($account->id, $existing->user_id);
        $this->assertSame('Nama Diperbarui', $account->refresh()->name);

        $ids = Pegawai::query()->orderBy('id')->pluck('id')->all();
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 data pegawai berhasil diimpor (0 ditambahkan, 2 diperbarui).');
        $this->assertSame($ids, Pegawai::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_exported_profiles_can_be_imported_back_to_update_the_same_records(): void
    {
        $pegawai = Pegawai::query()->create([
            ...$this->organization(),
            'nik' => 'ROUND.001', 'nama' => 'Pegawai Ekspor', 'jenis_kelamin' => 'Laki-laki',
            'status_pegawai' => 'Tetap', 'tanggal_mulai_bekerja' => '2026-01-01', 'nomor_telepon' => '081234567890',
        ]);
        $response = $this->actingAs($this->admin)->postJson('/absensi/admin-web/master-data/pegawai/export', ['all' => true])->assertOk();
        $spreadsheet = $this->readExport($response);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('B2', 'Nama Dari Excel');
        $sheet->setCellValue('C2', 'Ners');
        $file = $this->excelUpload([$sheet->rangeToArray('A2:K2')[0]], self::PROFILE_HEADERS);
        $spreadsheet->disconnectWorksheets();

        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 data pegawai berhasil diimpor (0 ditambahkan, 1 diperbarui).');
        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('Nama Dari Excel', $pegawai->refresh()->nama);
        $this->assertSame('Ners', $pegawai->profesi);
        $this->assertSame('081234567890', $pegawai->nomor_telepon);
    }

    public function test_import_rolls_back_new_and_updated_rows_when_employee_identifiers_conflict(): void
    {
        $first = Pegawai::query()->create(['nik' => 'CONFLICT.001', 'nama' => 'Pegawai Pertama', 'nomor_telepon' => '081211111111']);
        $second = Pegawai::query()->create(['nik' => 'CONFLICT.002', 'nama' => 'Pegawai Kedua', 'email' => 'kedua@rsarsy.co.id']);
        $file = $this->excelUpload([
            ['CONFLICT.003', 'Pegawai Baru', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, null, null],
            ['CONFLICT.001', 'Nama Berubah', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, null, null],
            ['CONFLICT.002', 'Identitas Bentrok', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, '081211111111', 'kedua@rsarsy.co.id'],
        ], self::PROFILE_HEADERS);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasErrors('file', 'Baris 4: NIK, nomor telepon, atau email mengarah ke pegawai yang berbeda. Periksa kembali identitas pegawai.');
        $this->assertDatabaseCount('pegawais', 2);
        $this->assertSame('Pegawai Pertama', $first->refresh()->nama);
        $this->assertSame('081211111111', $first->nomor_telepon);
        $this->assertSame('Pegawai Kedua', $second->refresh()->nama);
    }

    public function test_import_does_not_replace_an_existing_nik_based_on_another_employees_contact(): void
    {
        $existing = Pegawai::query()->create(['nik' => 'KEEP.001', 'nama' => 'Pegawai Asli', 'email' => 'asli@rsarsy.co.id']);
        $file = $this->excelUpload([
            ['OTHER.001', 'Pegawai Lain', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, null, 'asli@rsarsy.co.id'],
        ], self::PROFILE_HEADERS);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasErrors('file', 'Baris 2: nomor telepon atau email sudah digunakan pegawai dengan NIK berbeda.');
        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('KEEP.001', $existing->refresh()->nik);
        $this->assertSame('Pegawai Asli', $existing->nama);
    }

    public function test_import_rejects_two_rows_that_match_the_same_employee_using_different_contacts(): void
    {
        $existing = Pegawai::query()->create(['nama' => 'Pegawai Asli', 'nomor_telepon' => '081211111111', 'email' => 'asli@rsarsy.co.id']);
        $file = $this->excelUpload([
            ['Nama Satu', '01/01/1990', '081211111111', null],
            ['Nama Dua', '02/02/1992', '081222222222', 'asli@rsarsy.co.id'],
        ], ['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telepon', 'Email']);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasErrors('file', 'Baris 3: pegawai yang sama sudah tercantum pada baris 2.');
        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('Pegawai Asli', $existing->refresh()->nama);
        $this->assertSame('asli@rsarsy.co.id', $existing->email);
    }

    public function test_import_preserves_account_email_and_rolls_back_the_entire_file_if_it_is_changed(): void
    {
        $account = User::factory()->create(['name' => 'Akun Asli', 'email' => 'akun.asli@rsarsy.co.id']);
        $existing = Pegawai::query()->create(['nik' => 'ACCOUNT.001', 'nama' => 'Akun Asli', 'email' => $account->email]);
        $existing->forceFill(['user_id' => $account->id])->save();
        $file = $this->excelUpload([
            ['ACCOUNT.002', 'Pegawai Baru', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, null, null],
            ['ACCOUNT.001', 'Nama Berubah', null, 'Unit Rawat Jalan', 'Staff Unit Rawat Jalan', 'Laki-laki', 'Tetap', '01/01/2026', null, null, 'akun.berubah@rsarsy.co.id'],
        ], self::PROFILE_HEADERS);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasErrors('file', 'Baris 3: Email tidak dapat diubah selama akun terkait masih ada. Hapus akun terkait terlebih dahulu jika email harus diganti.');
        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('Akun Asli', $existing->refresh()->nama);
        $this->assertSame($account->email, $existing->email);
        $this->assertSame($account->id, $existing->user_id);
        $this->assertSame('Akun Asli', $account->refresh()->name);
    }

    public function test_import_requires_update_permission_only_when_existing_employees_are_matched(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PEGAWAI_VIEW, AccessPermission::PEGAWAI_CREATE]);
        $this->actingAs($user)->post('/absensi/admin-web/master-data/pegawai/import', [
            'file' => $this->excelUpload([['Pegawai Asli', '01/01/1990', '081211111111']]),
        ])->assertSessionHasNoErrors()->assertRedirect('/absensi/admin-web/master-data/pegawai');

        $file = $this->excelUpload([
            ['Pegawai Baru', '02/02/1992', '081222222222'],
            ['Nama Berubah', '01/01/1990', '081211111111'],
        ]);
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseCount('pegawais', 1);
        $this->assertDatabaseHas('pegawais', ['nama' => 'Pegawai Asli']);

        $user->givePermissionTo(AccessPermission::PEGAWAI_UPDATE);
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '2 data pegawai berhasil diimpor (1 ditambahkan, 1 diperbarui).');
        $this->assertDatabaseCount('pegawais', 2);
    }

    public function test_super_admin_can_create_pegawai_manually(): void
    {
        $organization = $this->organization();

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai', [
                ...$organization,
                'nik' => '101.201.026',
                'nama' => '  Siti   Rahmawati ',
                'profesi' => 'Ners',
                'jenis_kelamin' => 'Perempuan',
                'status_pegawai' => 'Tetap',
                'tanggal_mulai_bekerja' => '2026-01-15',
                'tanggal_lahir' => '1994-06-15',
                'nomor_telepon' => '+62 812-3456-7890',
                'email' => ' SITI.RAHMAWATI@RSARSY.CO.ID ',
            ])
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', 'Data pegawai berhasil ditambahkan.');

        $this->assertDatabaseHas('pegawais', [
            'nik' => '101.201.026',
            'nama' => 'Siti Rahmawati',
            'jenis_kelamin' => 'Perempuan',
            'status_pegawai' => 'Tetap',
            'tanggal_lahir' => '1994-06-15 00:00:00',
            'nomor_telepon' => '081234567890',
            'email' => 'siti.rahmawati@rsarsy.co.id',
        ]);
        $this->assertNull(Pegawai::query()->where('nik', '101.201.026')->firstOrFail()->user_id);
    }

    public function test_manual_create_validates_required_fields_and_duplicate_contact_data(): void
    {
        $organization = $this->organization();
        Pegawai::query()->create([
            'nama' => 'Pegawai Lama',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081234567890',
            'email' => 'pegawai.lama@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai', [
                ...$organization,
                'nik' => '',
                'nama' => '',
                'jenis_kelamin' => 'Perempuan',
                'status_pegawai' => 'Tetap',
                'tanggal_mulai_bekerja' => '2026-01-01',
                'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
                'nomor_telepon' => '0812-3456-7890',
                'email' => 'PEGAWAI.LAMA@RSARSY.CO.ID',
            ])
            ->assertSessionHasErrors(['nik', 'nama', 'tanggal_lahir', 'nomor_telepon', 'email']);

        $this->assertDatabaseCount('pegawais', 1);
    }

    public function test_import_is_atomic_and_reports_the_source_row_when_data_is_invalid(): void
    {
        $file = $this->excelUpload([
            ['Data Valid', '01/01/1990', '081111111111'],
            ['Tanggal Salah', '31/02/2020', '082222222222'],
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $file])
            ->assertSessionHasErrors('file', 'Baris 3: tanggal lahir wajib diisi dengan format DD/MM/YYYY.');

        $this->assertDatabaseCount('pegawais', 0);
    }

    public function test_import_rejects_duplicate_phones_in_file_and_updates_existing_employees_by_phone(): void
    {
        $existing = Pegawai::query()->create([
            ...$this->organization(),
            'nik' => 'LEGACY.001',
            'nama' => 'Pegawai Lama',
            'profesi' => 'Ners',
            'status_pegawai' => 'Tetap',
            'tanggal_mulai_bekerja' => '2026-01-01',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081200000000',
            'email' => 'legacy@rsarsy.co.id',
        ]);

        $duplicateInFile = $this->excelUpload([
            ['Pegawai Satu', '01/01/1991', '081233333333'],
            ['Pegawai Dua', '02/02/1992', '+62 812 3333 3333'],
        ]);
        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $duplicateInFile])
            ->assertSessionHasErrors('file');

        $duplicateInDatabase = $this->excelUpload([
            ['Pegawai Baru', '03/03/1993', '0812-0000-0000'],
        ]);
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $duplicateInDatabase])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 data pegawai berhasil diimpor (0 ditambahkan, 1 diperbarui).');

        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('Pegawai Baru', $existing->refresh()->nama);
        $this->assertSame('1993-03-03', $existing->tanggal_lahir->format('Y-m-d'));
        $this->assertSame('LEGACY.001', $existing->nik);
        $this->assertSame('legacy@rsarsy.co.id', $existing->email);
        $this->assertSame('Ners', $existing->profesi);
        $this->assertSame('Tetap', $existing->status_pegawai);
        $this->assertSame('2026-01-01', $existing->tanggal_mulai_bekerja->format('Y-m-d'));
        $this->assertSame($this->organization()['unit_kerja_id'], $existing->unit_kerja_id);
        $this->assertSame($this->organization()['jabatan_id'], $existing->jabatan_id);
    }

    public function test_import_rejects_duplicate_emails_in_file_and_updates_existing_employees_by_email(): void
    {
        $existing = Pegawai::query()->create([
            'nama' => 'Pegawai Lama',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081200000000',
            'email' => 'pegawai.lama@rsarsy.co.id',
        ]);

        $duplicateInFile = $this->excelUpload([
            ['Pegawai Satu', '01/01/1991', '081233333331', 'kontak@rsarsy.co.id'],
            ['Pegawai Dua', '02/02/1992', '081233333332', ' KONTAK@RSARSY.CO.ID '],
        ], ['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telepon', 'Email']);
        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $duplicateInFile])
            ->assertSessionHasErrors('file');

        $duplicateInDatabase = $this->excelUpload([
            ['Pegawai Baru', '03/03/1993', '081233333333', 'PEGAWAI.LAMA@RSARSY.CO.ID'],
        ], ['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telepon', 'Email']);
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $duplicateInDatabase])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 data pegawai berhasil diimpor (0 ditambahkan, 1 diperbarui).');

        $this->assertDatabaseCount('pegawais', 1);
        $this->assertSame('Pegawai Baru', $existing->refresh()->nama);
        $this->assertSame('081233333333', $existing->nomor_telepon);
        $this->assertSame('pegawai.lama@rsarsy.co.id', $existing->email);
    }

    public function test_import_requires_the_official_headers_and_excel_format(): void
    {
        $wrongHeader = $this->excelUpload(
            [['Siti Aminah', '17/08/1995', '081234567890']],
            ['Nama', 'Lahir', 'Telepon'],
        );

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $wrongHeader])
            ->assertSessionHasErrors('file');

        $this->post('/absensi/admin-web/master-data/pegawai/import', [
            'file' => UploadedFile::fake()->create('pegawai.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('file');
    }

    public function test_super_admin_can_update_and_delete_pegawai(): void
    {
        $organization = $this->organization();
        $pegawai = Pegawai::query()->create([
            ...$organization,
            'nik' => '100.201.020',
            'nama' => 'Nama Awal',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081234567890',
            'email' => 'nama.awal@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)->put("/absensi/admin-web/master-data/pegawai/{$pegawai->id}", [
            ...$organization,
            'nik' => '100.201.020',
            'nama' => '  Nama   Baru ',
            'profesi' => 'Ners',
            'jenis_kelamin' => 'Laki-laki',
            'status_pegawai' => 'Tidak Tetap',
            'tanggal_mulai_bekerja' => '2020-01-01',
            'tanggal_lahir' => '1992-03-04',
            'nomor_telepon' => '+62 812-9999-0000',
            'email' => ' NAMA.BARU@RSARSY.CO.ID ',
        ])->assertRedirect('/absensi/admin-web/master-data/pegawai');

        $this->assertDatabaseHas('pegawais', [
            'id' => $pegawai->id,
            'nama' => 'Nama Baru',
            'status_pegawai' => 'Tidak Tetap',
            'tanggal_lahir' => '1992-03-04 00:00:00',
            'nomor_telepon' => '081299990000',
            'email' => 'nama.baru@rsarsy.co.id',
        ]);

        $this->delete("/absensi/admin-web/master-data/pegawai/{$pegawai->id}")
            ->assertRedirect('/absensi/admin-web/master-data/pegawai');
        $this->assertDatabaseMissing('pegawais', ['id' => $pegawai->id]);
    }

    public function test_index_exposes_employment_overview_and_applies_search_and_status_filters(): void
    {
        $organization = $this->organization();
        Pegawai::query()->create([
            ...$organization,
            'nik' => '001.201.026',
            'nama' => 'Siti Aminah',
            'jenis_kelamin' => 'Perempuan',
            'status_pegawai' => 'Tetap',
            'tanggal_mulai_bekerja' => now()->startOfYear()->format('Y-m-d'),
            'tanggal_lahir' => now()->subYears(30)->startOfMonth()->addDays(3)->format('Y-m-d'),
            'nomor_telepon' => '081211111111',
            'email' => 'siti@rsarsy.co.id',
        ]);
        Pegawai::query()->create([
            ...$organization,
            'nik' => '002.201.026',
            'nama' => 'Budi Santoso',
            'jenis_kelamin' => 'Laki-laki',
            'status_pegawai' => 'Tidak Tetap',
            'tanggal_mulai_bekerja' => '2020-01-01',
            'tanggal_lahir' => now()->subYears(40)->addMonth()->format('Y-m-d'),
            'nomor_telepon' => '081222222222',
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/pegawai?search=siti%40rsarsy.co.id&status=Tetap')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/pegawai/Index')
                ->where('overview.total', 2)
                ->where('overview.tetap', 1)
                ->where('overview.tidak_tetap', 1)
                ->where('overview.joined_this_year', 1)
                ->where('pegawais.total', 1)
                ->where('pegawais.data.0.nama', 'Siti Aminah')
                ->where('pegawais.data.0.email', 'siti@rsarsy.co.id')
                ->where('pegawais.data.0.status_pegawai', 'Tetap')
                ->where('filters.search', 'siti@rsarsy.co.id')
                ->where('filters.status', 'Tetap'));
    }

    public function test_super_admin_can_create_employee_user_and_send_activation_invitation(): void
    {
        Notification::fake();
        $pegawai = Pegawai::query()->create([
            'nik' => 'INV.001',
            'nama' => 'Pegawai Undangan',
            'email' => 'undangan@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation")
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', 'Akun pegawai berhasil dibuat dan undangan aktivasi dikirim ke undangan@rsarsy.co.id.');

        $user = User::query()->where('email', 'undangan@rsarsy.co.id')->firstOrFail();
        $this->assertSame($user->id, $pegawai->refresh()->user_id);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('pegawai'));
        $this->assertFalse($user->hasRole('super-admin'));
        Notification::assertSentTo($user, EmployeeAccountInvitation::class);

        $this->get('/absensi/admin-web/master-data/pegawai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pegawais.data.0.account_status', 'pending')
                ->where('pegawais.data.0.user_id', $user->id));
    }

    public function test_invitation_requires_an_available_employee_email(): void
    {
        Notification::fake();
        $withoutEmail = Pegawai::query()->create(['nama' => 'Tanpa Email']);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$withoutEmail->id}/user-invitation")
            ->assertSessionHasErrors('pegawai', 'Isi email pegawai terlebih dahulu sebelum membuat akun.');

        $existing = User::factory()->create(['email' => 'sudah.ada@rsarsy.co.id']);
        $duplicateEmail = Pegawai::query()->create([
            'nama' => 'Email Terpakai',
            'email' => $existing->email,
        ]);

        $this->post("/absensi/admin-web/master-data/pegawai/{$duplicateEmail->id}/user-invitation")
            ->assertSessionHasErrors('pegawai', 'Email pegawai sudah digunakan oleh akun lain.');

        $this->assertNull($withoutEmail->refresh()->user_id);
        $this->assertNull($duplicateEmail->refresh()->user_id);
        Notification::assertNothingSent();
    }

    public function test_super_admin_can_resend_invitation_without_creating_a_duplicate_user(): void
    {
        Notification::fake();
        $pegawai = Pegawai::query()->create([
            'nama' => 'Kirim Ulang',
            'email' => 'kirim.ulang@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation");
        $user = $pegawai->refresh()->user;

        Notification::fake();
        $this->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation")
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', 'Undangan aktivasi berhasil dikirim ulang ke kirim.ulang@rsarsy.co.id.');

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($user->id, $pegawai->refresh()->user_id);
        Notification::assertSentTo($user, EmployeeAccountInvitation::class);
    }

    public function test_super_admin_can_send_activation_invitations_to_selected_employees_in_batch(): void
    {
        Notification::fake();
        $newAccount = Pegawai::query()->create([
            'nama' => 'Akun Batch Baru',
            'email' => 'batch.baru@rsarsy.co.id',
        ]);
        $pendingAccount = Pegawai::query()->create([
            'nama' => 'Akun Batch Pending',
            'email' => 'batch.pending@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pendingAccount->id}/user-invitation");
        $pendingUser = $pendingAccount->refresh()->user;
        Notification::fake();

        $this->post('/absensi/admin-web/master-data/pegawai/user-invitations/batch', [
            'pegawai_ids' => [$newAccount->id, $pendingAccount->id],
        ])
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', '2 email aktivasi berhasil dikirim (1 akun baru dan 1 undangan ulang).');

        $newUser = $newAccount->refresh()->user;
        $this->assertNotNull($newUser);
        $this->assertSame($pendingUser->id, $pendingAccount->refresh()->user_id);
        $this->assertTrue($newUser->hasRole('pegawai'));
        Notification::assertSentTo($newUser, EmployeeAccountInvitation::class);
        Notification::assertSentTo($pendingUser, EmployeeAccountInvitation::class);
        Notification::assertCount(2);
    }

    public function test_batch_invitation_validates_every_selection_before_creating_accounts(): void
    {
        Notification::fake();
        $valid = Pegawai::query()->create([
            'nama' => 'Batch Valid',
            'email' => 'batch.valid@rsarsy.co.id',
        ]);
        $withoutEmail = Pegawai::query()->create([
            'nama' => 'Batch Tanpa Email',
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/user-invitations/batch', [
                'pegawai_ids' => [$valid->id, $withoutEmail->id],
            ])
            ->assertSessionHasErrors('pegawai_ids', 'Undangan untuk Batch Tanpa Email tidak dapat diproses: Isi email pegawai terlebih dahulu sebelum membuat akun.');

        $this->assertNull($valid->refresh()->user_id);
        $this->assertNull($withoutEmail->refresh()->user_id);
        Notification::assertNothingSent();
    }

    public function test_batch_invitation_requires_at_least_one_unique_employee(): void
    {
        $pegawai = Pegawai::query()->create([
            'nama' => 'Batch Duplikat',
            'email' => 'batch.duplikat@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai/user-invitations/batch', ['pegawai_ids' => []])
            ->assertSessionHasErrors('pegawai_ids');

        $this->post('/absensi/admin-web/master-data/pegawai/user-invitations/batch', [
            'pegawai_ids' => [$pegawai->id, $pegawai->id],
        ])->assertSessionHasErrors('pegawai_ids.1');
    }

    public function test_employee_can_set_password_and_verify_email_from_invitation(): void
    {
        config()->set('app.account_activation_url', 'https://presensi.example.com');
        Notification::fake();
        $pegawai = Pegawai::query()->create([
            'nama' => 'Aktivasi Akun',
            'email' => 'aktivasi@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation");
        $this->post('/logout');

        $user = $pegawai->refresh()->user;
        Notification::assertSentTo($user, EmployeeAccountInvitation::class, function (EmployeeAccountInvitation $notification) use ($user) {
            $this->get($notification->toMail($user)->actionUrl)
                ->assertOk()
                ->assertSee('rel="manifest"', escape: false)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/ResetPassword')
                    ->where('invitation', true));

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'PasswordBaru123!',
                'password_confirmation' => 'PasswordBaru123!',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/')
                ->assertSessionHas('status', 'Akun berhasil diaktifkan dan email telah terverifikasi. Pasang aplikasi sesuai perangkat Anda, lalu masuk dengan password yang baru dibuat.');

            $this->assertGuest();
            $this->get('/')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Welcome')
                    ->where('status', 'Akun berhasil diaktifkan dan email telah terverifikasi. Pasang aplikasi sesuai perangkat Anda, lalu masuk dengan password yang baru dibuat.'));

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'PasswordLain123!',
                'password_confirmation' => 'PasswordLain123!',
            ])->assertSessionHasErrors('email');

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'PasswordBaru123!',
            ])->assertRedirect('/absensi/mobile');
            $this->get('/absensi/mobile')->assertOk();
            $this->post('/logout');

            return true;
        });

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('PasswordBaru123!', $user->password));

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation")
            ->assertSessionHasErrors('pegawai', 'Akun pegawai sudah aktif dan emailnya telah terverifikasi.');
    }

    public function test_employee_with_an_account_cannot_be_deleted_or_change_email(): void
    {
        Notification::fake();
        $organization = $this->organization();
        $pegawai = Pegawai::query()->create([
            ...$organization,
            'nik' => 'LOCK.001',
            'nama' => 'Pegawai Terkait',
            'jenis_kelamin' => 'Perempuan',
            'status_pegawai' => 'Tetap',
            'tanggal_mulai_bekerja' => '2026-01-01',
            'email' => 'terkait@rsarsy.co.id',
        ]);

        $this->actingAs($this->admin)
            ->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation");

        $this->put("/absensi/admin-web/master-data/pegawai/{$pegawai->id}", [
            ...$organization,
            'nik' => $pegawai->nik,
            'nama' => $pegawai->nama,
            'jenis_kelamin' => $pegawai->jenis_kelamin,
            'status_pegawai' => $pegawai->status_pegawai,
            'tanggal_mulai_bekerja' => '2026-01-01',
            'email' => 'email.baru@rsarsy.co.id',
        ])->assertSessionHasErrors('email');

        $this->delete("/absensi/admin-web/master-data/pegawai/{$pegawai->id}")
            ->assertSessionHasErrors('pegawai');

        $this->assertDatabaseHas('pegawais', ['id' => $pegawai->id, 'email' => 'terkait@rsarsy.co.id']);
        $this->assertDatabaseHas('users', ['id' => $pegawai->refresh()->user_id]);
    }

    public function test_action_routes_require_the_corresponding_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::PEGAWAI_VIEW);
        $pegawai = Pegawai::query()->create([
            'nama' => 'Pegawai Terbatas',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081234567890',
        ]);

        $this->actingAs($user)->get('/absensi/admin-web/master-data/pegawai/template')->assertForbidden();
        $this->post('/absensi/admin-web/master-data/pegawai', [])->assertForbidden();
        $this->post('/absensi/admin-web/master-data/pegawai/import', ['file' => $this->excelUpload([])])->assertForbidden();
        $this->post('/absensi/admin-web/master-data/pegawai/user-invitations/batch', ['pegawai_ids' => [$pegawai->id]])->assertForbidden();
        $this->post("/absensi/admin-web/master-data/pegawai/{$pegawai->id}/user-invitation")->assertForbidden();
        $this->put("/absensi/admin-web/master-data/pegawai/{$pegawai->id}", [])->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/pegawai/{$pegawai->id}")->assertForbidden();
    }

    private function readExport(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'pegawai-export-');
        file_put_contents($path, $response->streamedContent());
        $this->temporaryFiles[] = $path;

        return IOFactory::load($path);
    }

    /**
     * @return array{unit_kerja_id: int, jabatan_id: int}
     */
    private function organization(): array
    {
        $unit = UnitKerja::query()
            ->where('nama', 'Unit Rawat Jalan')
            ->with('jabatans')
            ->firstOrFail();

        return [
            'unit_kerja_id' => (int) $unit->id,
            'jabatan_id' => (int) $unit->jabatans->firstWhere('nama', 'Staff Unit Rawat Jalan')->id,
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function excelUpload(array $rows, array $headers = ['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telfon']): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headers, ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'pegawai-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return new UploadedFile(
            $path,
            'pegawai.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
