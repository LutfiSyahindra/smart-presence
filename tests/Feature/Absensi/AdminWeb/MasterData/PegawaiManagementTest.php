<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Pegawai;
use App\Models\User;
use App\Services\Absensi\AdminWeb\MasterData\Pegawai\PegawaiSpreadsheetService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PegawaiManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
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
        $this->get('/absensi/admin-web/master-data/pegawai')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/master-data/pegawai')->assertForbidden();

        $user->givePermissionTo(AccessPermission::PEGAWAI_VIEW);

        $this->get('/absensi/admin-web/master-data/pegawai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/pegawai/Index')
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
    }

    public function test_super_admin_can_download_the_official_excel_template(): void
    {
        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/pegawai/template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('template-import-pegawai.xlsx');
    }

    public function test_excel_template_contains_the_required_columns_and_safe_phone_format(): void
    {
        $spreadsheet = app(PegawaiSpreadsheetService::class)->template();
        $sheet = $spreadsheet->getSheetByName('Data Pegawai');

        $this->assertNotNull($sheet);
        $this->assertSame(['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telfon'], $sheet->rangeToArray('A1:C1')[0]);
        $this->assertSame('@', $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
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
    }

    public function test_super_admin_can_create_pegawai_manually(): void
    {
        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai', [
                'nama' => '  Siti   Rahmawati ',
                'tanggal_lahir' => '1994-06-15',
                'nomor_telepon' => '+62 812-3456-7890',
            ])
            ->assertRedirect('/absensi/admin-web/master-data/pegawai')
            ->assertSessionHas('success', 'Data pegawai berhasil ditambahkan.');

        $this->assertDatabaseHas('pegawais', [
            'nama' => 'Siti Rahmawati',
            'tanggal_lahir' => '1994-06-15 00:00:00',
            'nomor_telepon' => '081234567890',
        ]);
    }

    public function test_manual_create_validates_required_fields_and_duplicate_phone_number(): void
    {
        Pegawai::query()->create([
            'nama' => 'Pegawai Lama',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081234567890',
        ]);

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/master-data/pegawai', [
                'nama' => '',
                'tanggal_lahir' => now()->addDay()->format('Y-m-d'),
                'nomor_telepon' => '0812-3456-7890',
            ])
            ->assertSessionHasErrors(['nama', 'tanggal_lahir', 'nomor_telepon']);

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

    public function test_import_rejects_duplicate_phone_numbers_in_file_and_database(): void
    {
        Pegawai::query()->create([
            'nama' => 'Pegawai Lama',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081200000000',
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
            ->assertSessionHasErrors('file', 'Nomor telfon pada baris 2 sudah terdaftar. Perbarui file lalu coba kembali.');

        $this->assertDatabaseCount('pegawais', 1);
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
        $pegawai = Pegawai::query()->create([
            'nama' => 'Nama Awal',
            'tanggal_lahir' => '1990-01-01',
            'nomor_telepon' => '081234567890',
        ]);

        $this->actingAs($this->admin)->put("/absensi/admin-web/master-data/pegawai/{$pegawai->id}", [
            'nama' => '  Nama   Baru ',
            'tanggal_lahir' => '1992-03-04',
            'nomor_telepon' => '+62 812-9999-0000',
        ])->assertRedirect('/absensi/admin-web/master-data/pegawai');

        $this->assertDatabaseHas('pegawais', [
            'id' => $pegawai->id,
            'nama' => 'Nama Baru',
            'tanggal_lahir' => '1992-03-04 00:00:00',
            'nomor_telepon' => '081299990000',
        ]);

        $this->delete("/absensi/admin-web/master-data/pegawai/{$pegawai->id}")
            ->assertRedirect('/absensi/admin-web/master-data/pegawai');
        $this->assertDatabaseMissing('pegawais', ['id' => $pegawai->id]);
    }

    public function test_index_exposes_overview_and_applies_search_and_birthday_filters(): void
    {
        Pegawai::query()->create([
            'nama' => 'Siti Aminah',
            'tanggal_lahir' => now()->subYears(30)->startOfMonth()->addDays(3)->format('Y-m-d'),
            'nomor_telepon' => '081211111111',
        ]);
        Pegawai::query()->create([
            'nama' => 'Budi Santoso',
            'tanggal_lahir' => now()->subYears(40)->addMonth()->format('Y-m-d'),
            'nomor_telepon' => '081222222222',
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/pegawai?search=siti&birthday=this_month')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/pegawai/Index')
                ->where('overview.total', 2)
                ->where('overview.birthdays_this_month', 1)
                ->where('pegawais.total', 1)
                ->where('pegawais.data.0.nama', 'Siti Aminah')
                ->where('filters.search', 'siti')
                ->where('filters.birthday', 'this_month'));
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
        $this->put("/absensi/admin-web/master-data/pegawai/{$pegawai->id}", [])->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/pegawai/{$pegawai->id}")->assertForbidden();
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
