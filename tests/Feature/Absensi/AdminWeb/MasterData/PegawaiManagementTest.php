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

    public function test_import_rejects_duplicate_emails_in_file_and_database(): void
    {
        Pegawai::query()->create([
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
            ->assertSessionHasErrors('file', 'Email pada baris 2 sudah terdaftar. Perbarui file lalu coba kembali.');

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
