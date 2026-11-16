<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\LemburBalanceAdjustment;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\AdminWeb\MasterData\Lembur\LemburBalanceService;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MasterLemburBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/master-data/lembur';

    private User $admin;

    private User $employee;

    private User $approver;

    private Pegawai $pegawai;

    private Shift $shift;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-12 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        Bus::fake();
        $this->admin = User::factory()->create(['name' => 'Admin SDM']);
        $this->admin->assignRole('super-admin');
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->approver = User::factory()->create();
        $this->approver->assignRole('pegawai');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia', 'nik' => '0001',
            'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id, 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $line = ApprovalLine::query()->create(['type' => 'lembur']);
        $line->jabatans()->attach($position->id, ['type' => 'lembur']);
        $line->steps()->create(['user_id' => $this->approver->id, 'level' => 1]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function setBalance(int $minutes): void
    {
        $this->put(self::URL.'/saldo', ['pegawai_id' => $this->pegawai->id, 'minutes' => $minutes, 'note' => 'Penyesuaian saldo'])
            ->assertSessionHasNoErrors();
    }

    private function excel(array $rows, string $format = 'xlsx', array $headers = ['NIK', 'Nama Pegawai', 'Saldo Lembur (Menit)']): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Saldo Lembur');
        $sheet->fromArray([$headers]);
        foreach ($rows as $index => $row) {
            $sheet->setCellValueExplicit('A'.($index + 2), (string) $row[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.($index + 2), (string) $row[1], DataType::TYPE_STRING);
            $sheet->setCellValue('C'.($index + 2), $row[2]);
            if (array_key_exists(3, $row)) {
                $sheet->setCellValue('D'.($index + 2), $row[3]);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'saldo-lembur-');
        $this->files[] = $path;
        ($format === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'saldo.'.$format, null, null, true);
    }

    private function usage(int $minutes = 60, string $date = '2026-11-13'): LemburRequest
    {
        return app(LemburService::class)->submitUsage($this->employee, ['tanggal_lembur' => $date,
            'shift_id' => $this->shift->id, 'usage_type' => 'late_arrival', 'duration_minutes' => $minutes, 'alasan' => 'Keperluan keluarga']);
    }

    public function test_master_only_lists_balances_and_supports_employee_search_and_unit_filter(): void
    {
        Pegawai::query()->create(['nama' => 'Budi', 'nik' => '0002']);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/master-data/lembur/Index')->missing('requests')->missing('shifts')
            ->where('employees.total', 2)->where('can.assign', true));
        $this->get(self::URL.'?search=0001&unit_id='.$this->pegawai->unit_kerja_id)->assertInertia(fn (Assert $page) => $page
            ->where('employees.total', 1)->where('employees.data.0.name', 'Nadia'));
        $this->post(self::URL)->assertMethodNotAllowed();
        $this->get(self::URL.'/123')->assertNotFound();
    }

    public function test_excel_employee_options_are_loaded_on_demand_and_include_other_pages(): void
    {
        for ($index = 2; $index <= 18; $index++) {
            Pegawai::query()->create(['nama' => 'Pegawai '.$index, 'nik' => sprintf('%04d', $index)]);
        }
        Pegawai::query()->create(['nama' => 'Tanpa NIK']);
        $initial = $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->missing('employeeOptions')->has('employees.data', 15));
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'absensi/admin-web/master-data/lembur/Index',
            'X-Inertia-Partial-Data' => 'employeeOptions', 'X-Inertia-Version' => $initial->viewData('page')['version']];
        $this->get(self::URL.'?search=Nadia', $headers)->assertOk()->assertJsonCount(18, 'props.employeeOptions');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::MASTER_LEMBUR_VIEW]);
        $this->actingAs($viewer)->get(self::URL, $headers)->assertOk()->assertJsonCount(0, 'props.employeeOptions');
        $this->postJson(self::URL.'/template', ['scope' => 'all'])->assertForbidden();
    }

    public function test_template_rejects_more_than_one_thousand_employees_without_silently_truncating(): void
    {
        $employees = [];
        for ($index = 2; $index <= 1001; $index++) {
            $employees[] = ['nama' => 'Pegawai '.$index, 'nik' => sprintf('%04d', $index)];
        }
        Pegawai::query()->insert($employees);
        $this->postJson(self::URL.'/template', ['scope' => 'all'])->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->postJson(self::URL.'/template', ['scope' => 'employees', 'pegawai_ids' => [$this->pegawai->id]])->assertOk();
    }

    public function test_manual_set_is_absolute_audited_and_immediately_visible_to_mobile(): void
    {
        $this->setBalance(150);
        $this->setBalance(240);
        $this->assertSame(240, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->assertDatabaseCount('lembur_requests', 0);
        $this->assertSame(90, LemburBalanceAdjustment::query()->latest('id')->first()->delta_minutes);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('employees.data.0.last_adjustment.by', 'Admin SDM')
            ->where('employees.data.0.last_adjustment.before_minutes', 150)->where('employees.data.0.last_adjustment.after_minutes', 240));
        $this->actingAs($this->employee)->get('/absensi/mobile/lembur')->assertInertia(fn (Assert $page) => $page->where('balance.available_minutes', 240));
    }

    public function test_manual_credit_can_be_reserved_approved_and_returned_after_cancellation(): void
    {
        $this->setBalance(180);
        $usage = $this->usage();
        $this->assertSame(60, $usage->manual_balance_minutes);
        $this->assertCount(0, $usage->sources);
        $this->assertSame(120, app(LemburService::class)->balance($this->employee)['available_minutes']);
        app(LemburService::class)->decide($usage, $this->approver, ['decision' => 'approved']);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['used_minutes']);
        app(ConfigurationService::class)->update(['cancel_approved_lembur' => true], $this->admin->id);
        app(LemburService::class)->cancel($usage, $this->employee);
        $this->assertSame(180, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_setting_zero_preserves_reserved_usage_and_cancellation_restores_it(): void
    {
        $this->setBalance(180);
        $usage = $this->usage();
        $this->setBalance(0);
        $this->assertSame(['earned_minutes' => 60, 'reserved_minutes' => 60, 'used_minutes' => 0, 'available_minutes' => 0],
            app(LemburService::class)->balance($this->employee));
        app(LemburService::class)->cancel($usage, $this->employee);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_excel_xlsx_and_xls_set_available_balance_with_blank_skip_and_zero(): void
    {
        $other = Pegawai::query()->create(['nama' => 'Budi', 'nik' => '0002']);
        $this->setBalance(120);
        foreach (['xlsx', 'xls'] as $format) {
            $this->post(self::URL.'/import', ['file' => $this->excel([['0001', 'Referensi nama', null], ['0002', 'Budi', 150]], $format),
                'note' => 'Saldo awal'])->assertSessionHasNoErrors();
            $this->assertSame(120, app(LemburService::class)->balance($this->employee)['available_minutes']);
            $this->assertSame(150, app(LemburService::class)->balances([$other->id])[$other->id]['available_minutes']);
        }
        $this->post(self::URL.'/import', ['file' => $this->excel([['0001', 'Nadia', 0]])])->assertSessionHasNoErrors();
        $this->assertSame(0, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->assertSame('excel', LemburBalanceAdjustment::query()->latest('id')->first()->source);
        $this->assertDatabaseCount('lembur_requests', 0);
    }

    public function test_excel_credit_is_usable_from_mobile_and_overdraft_is_rejected(): void
    {
        $this->post(self::URL.'/import', ['file' => $this->excel([['0001', 'Nadia', 150]])])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->post('/absensi/mobile/lembur/gunakan', ['tanggal_lembur' => '2026-11-13',
            'shift_id' => $this->shift->id, 'usage_type' => 'late_arrival', 'duration_minutes' => 60, 'alasan' => 'Keluarga'])->assertSessionHasNoErrors();
        $this->get('/absensi/mobile/lembur?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('balance.available_minutes', 90)->where('requests.data.0.manual_balance_minutes', 60));
        $this->postJson('/absensi/mobile/lembur/gunakan', ['tanggal_lembur' => '2026-11-14',
            'shift_id' => $this->shift->id, 'usage_type' => 'late_arrival', 'duration_minutes' => 91, 'alasan' => 'Keluarga'])
            ->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
    }

    public function test_excel_day_templates_are_rejected_without_changing_balances(): void
    {
        $this->setBalance(120);
        $headers = ['NIK', 'Nama Pegawai', 'Saldo Lembur (Hari)', 'Jam per Hari'];
        foreach (['xlsx', 'xls'] as $format) {
            $this->postJson(self::URL.'/import', ['file' => $this->excel([
                ['0001', 'Nadia', 2, 7],
            ], $format, $headers)])->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertSame(120, app(LemburService::class)->balance($this->employee)['available_minutes']);
            $this->assertDatabaseCount('lembur_balance_adjustments', 1);
        }
    }

    public function test_template_and_import_respect_unit_position_and_individual_selection(): void
    {
        $otherPosition = Jabatan::query()->create(['unit_kerja_id' => $this->pegawai->unit_kerja_id, 'kode' => 'ADM', 'nama' => 'Administrasi']);
        $other = Pegawai::query()->create(['nama' => 'Budi', 'nik' => '0002', 'unit_kerja_id' => $this->pegawai->unit_kerja_id, 'jabatan_id' => $otherPosition->id]);
        Pegawai::query()->create(['nama' => 'Citra', 'nik' => '0003']);
        Pegawai::query()->create(['nama' => 'Tanpa NIK']);
        $cases = [
            [['scope' => 'all'], ['0002', '0003', '0001']],
            [['scope' => 'organization', 'unit_id' => $this->pegawai->unit_kerja_id], ['0002', '0001']],
            [['scope' => 'organization', 'position_id' => $this->pegawai->jabatan_id], ['0001']],
            [['scope' => 'organization', 'unit_id' => $this->pegawai->unit_kerja_id, 'position_id' => $this->pegawai->jabatan_id], ['0001']],
            [['scope' => 'employees', 'pegawai_ids' => [$other->id]], ['0002']],
        ];
        foreach ($cases as [$selection, $niks]) {
            $response = $this->postJson(self::URL.'/template', $selection)->assertOk()->assertDownload('template-saldo-lembur.xlsx');
            $path = tempnam(sys_get_temp_dir(), 'selected-lembur-');
            $this->files[] = $path;
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            try {
                $sheet = $book->getActiveSheet();
                $this->assertSame('Saldo Lembur (Menit)', $sheet->getCell('C1')->getValue());
                $this->assertSame('C', $sheet->getHighestDataColumn());
                $this->assertSame($niks, array_column($sheet->rangeToArray('A2:A'.(count($niks) + 1)), 0));
                $this->assertNull($sheet->getCell('C2')->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        }
        foreach ([$cases[2][0], $cases[4][0]] as $selection) {
            $this->postJson(self::URL.'/import', [...$selection, 'file' => $this->excel([['0001', 'Nadia', 300], ['0003', 'Citra', 150]])])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertDatabaseCount('lembur_balance_adjustments', 0);
        }
        $this->post(self::URL.'/import', [...$cases[4][0], 'file' => $this->excel([['0002', 'Budi', 150]])])->assertSessionHasNoErrors();
        $this->assertSame(150, app(LemburService::class)->balances([$other->id])[$other->id]['available_minutes']);
        $this->get(self::URL.'?position_id='.$otherPosition->id)->assertInertia(fn (Assert $page) => $page
            ->where('employees.total', 1)->where('employees.data.0.name', 'Budi'));
    }

    public function test_selection_is_validated_before_generating_a_template_or_importing(): void
    {
        foreach ([['scope' => 'unknown'], ['scope' => 'organization'], ['scope' => 'employees', 'pegawai_ids' => []],
            ['scope' => 'employees', 'pegawai_ids' => [$this->pegawai->id, $this->pegawai->id]],
            ['scope' => 'employees', 'pegawai_ids' => [999999]]] as $selection) {
            $this->postJson(self::URL.'/template', $selection)->assertUnprocessable();
            $this->postJson(self::URL.'/import', [...$selection, 'file' => $this->excel([['0001', 'Nadia', 150]])])->assertUnprocessable();
            $this->assertDatabaseCount('lembur_balance_adjustments', 0);
        }
        $otherUnit = UnitKerja::query()->create(['kode' => 'POLI', 'nama' => 'Poliklinik']);
        $this->postJson(self::URL.'/template', ['scope' => 'organization', 'unit_id' => $otherUnit->id, 'position_id' => $this->pegawai->jabatan_id])
            ->assertUnprocessable()->assertJsonValidationErrors('position_id');
        $this->postJson(self::URL.'/template', ['scope' => 'organization', 'unit_id' => $otherUnit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_invalid_excel_rows_roll_back_the_entire_file(): void
    {
        $other = Pegawai::query()->create(['nama' => 'Budi', 'nik' => '0002']);
        $this->setBalance(120);
        foreach ([['UNKNOWN', 'Tidak ada', 50], ['0002', 'Budi', -1], ['0002', 'Budi', 1.5],
            ['0002', 'Budi', 525601], ['0002', 'Budi', '=60+60'], ['0001', 'Duplikat', 30]] as $badRow) {
            $this->postJson(self::URL.'/import', ['file' => $this->excel([['0001', 'Nadia', 300], $badRow])])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertSame(120, app(LemburService::class)->balance($this->employee)['available_minutes']);
            $this->assertSame(0, app(LemburService::class)->balances([$other->id])[$other->id]['available_minutes']);
            $this->assertDatabaseCount('lembur_balance_adjustments', 1);
        }
        $this->postJson(self::URL.'/import', ['file' => $this->excel([['0001', 'Nadia', null]])])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson(self::URL.'/import', ['file' => $this->excel([['0001', 'Nadia', 150]], 'xlsx', ['Wrong', 'Nama Pegawai', 'Saldo'])])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson(self::URL.'/import', ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'invalid excel')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_downloaded_template_preserves_nik_and_imports_minutes_without_conversion(): void
    {
        $response = $this->get(self::URL.'/template')->assertOk()->assertDownload('template-saldo-lembur.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'lembur-template-');
        $this->files[] = $path;
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        try {
            $sheet = $book->getSheetByName('Saldo Lembur');
            $this->assertSame('0001', $sheet->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            $this->assertSame('Saldo Lembur (Menit)', $sheet->getCell('C1')->getValue());
            $this->assertSame('C', $sheet->getHighestDataColumn());
            $this->assertNull($sheet->getCell('C2')->getValue());
            $this->assertNotNull($book->getSheetByName('Petunjuk'));
            $sheet->setCellValue('C2', 150);
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }

        $this->post(self::URL.'/import', ['file' => new UploadedFile($path, 'saldo.xlsx', null, null, true)])
            ->assertSessionHasNoErrors();
        $this->assertSame(150, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_master_assign_permission_is_separate_from_history_management(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::MASTER_LEMBUR_VIEW]);
        $this->actingAs($viewer)->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('can.assign', false));
        $this->get('/absensi/admin-web/lembur')->assertForbidden();
        $this->get(self::URL.'/template')->assertForbidden();
        $this->put(self::URL.'/saldo', ['pegawai_id' => $this->pegawai->id, 'minutes' => 60, 'note' => 'Awal'])->assertForbidden();
        $this->post(self::URL.'/import')->assertForbidden();
        $viewer->givePermissionTo(AccessPermission::MASTER_LEMBUR_ASSIGN);
        $this->setBalance(60);
        $viewer->revokePermissionTo(AccessPermission::MASTER_LEMBUR_VIEW);
        $this->get(self::URL)->assertForbidden();
        $this->get(self::URL.'/template')->assertForbidden();
        $this->put(self::URL.'/saldo')->assertForbidden();
        $this->post(self::URL.'/import')->assertForbidden();
    }

    public function test_manual_values_are_validated_and_service_batch_is_atomic(): void
    {
        foreach ([['minutes' => -1], ['minutes' => 1.5], ['minutes' => 525601], ['note' => '   '], ['pegawai_id' => 99999]] as $bad) {
            $this->putJson(self::URL.'/saldo', array_replace(['pegawai_id' => $this->pegawai->id, 'minutes' => 60, 'note' => 'Saldo awal'], $bad))->assertUnprocessable();
        }
        try {
            app(LemburBalanceService::class)->set([['pegawai_id' => $this->pegawai->id, 'minutes' => 120],
                ['pegawai_id' => 99999, 'minutes' => 60]], $this->admin->id, 'excel', 'Impor');
            $this->fail('Invalid batch should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('lembur_balance_adjustments', 0);
        }
    }

    public function test_old_usage_date_cannot_bypass_a_later_balance_reduction(): void
    {
        $this->setBalance(180);
        $this->travelTo('2026-11-13 09:00:00');
        $this->setBalance(0);
        $this->expectException(ValidationException::class);
        $this->usage(60, '2026-11-12');
    }

    public function test_native_credit_and_manual_adjustment_combine_without_invalidating_cancellation(): void
    {
        $service = app(LemburService::class);
        $source = $service->submit($this->employee, ['tanggal_lembur' => '2026-11-10', 'shift_id' => $this->shift->id,
            'before_minutes' => 0, 'before_break_minutes' => 0, 'after_minutes' => 180, 'after_break_minutes' => 0,
            'compensation' => 'time_off', 'alasan' => 'Pelayanan']);
        $service->decide($source, $this->approver, ['decision' => 'approved']);
        $this->setBalance(300);
        $usage = $this->usage(240);
        $this->assertSame(60, $usage->manual_balance_minutes);
        $this->assertSame(180, $usage->sources->sole()->pivot->minutes);
        $this->setBalance(15);
        $service->cancel($usage, $this->employee);
        $this->assertSame(255, $service->balance($this->employee)['available_minutes']);
        app(ConfigurationService::class)->update(['cancel_approved_lembur' => true], $this->admin->id);
        $this->setBalance(30);
        $this->postJson('/absensi/admin-web/lembur/'.$source->id.'/cancel', ['note' => 'Koreksi'])->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $this->assertSame('approved', $source->fresh()->status);
    }

    public function test_migration_preserves_existing_role_and_direct_history_access(): void
    {
        $migration = require database_path('migrations/2026_11_12_000003_add_lembur_balance_adjustments.php');
        $migration->down();
        $role = Role::query()->create(['name' => 'operator-lembur', 'guard_name' => 'web']);
        $role->givePermissionTo(['master-lembur.view', 'master-lembur.create', 'master-lembur.cancel']);
        $operator = User::factory()->create();
        $operator->givePermissionTo(['master-lembur.view', 'master-lembur.create', 'master-lembur.cancel']);
        $migration->up();
        $operator->refresh();
        foreach ([AccessPermission::LEMBUR_VIEW, AccessPermission::LEMBUR_CREATE, AccessPermission::LEMBUR_CANCEL] as $permission) {
            $this->assertTrue($operator->can($permission));
            $this->assertTrue($role->fresh()->hasPermissionTo($permission));
        }
        $this->assertTrue($operator->can(AccessPermission::MASTER_LEMBUR_VIEW));
        $this->assertFalse($operator->can(AccessPermission::MASTER_LEMBUR_ASSIGN));
    }
}
