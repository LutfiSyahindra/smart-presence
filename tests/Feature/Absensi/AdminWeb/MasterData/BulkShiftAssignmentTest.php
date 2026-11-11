<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentSpreadsheetService;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BulkShiftAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/master-data/shift/assign';

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->actingAs($this->admin);
    }

    private function employee(string $nik, array $attributes = []): Pegawai
    {
        $employee = Pegawai::query()->create(['nik' => $nik, 'nama' => 'Pegawai '.$nik, ...$attributes]);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        return $employee;
    }

    private function data(array $attributes = []): array
    {
        return ['month' => '2026-11', 'date_from' => '2026-11-01', 'date_to' => '2026-11-02', 'selection' => 'all', ...$attributes];
    }

    private function excel(array $rows, string $format = 'xlsx', ?array $headers = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jadwal Shift');
        $sheet->fromArray([$headers ?? ['NIK', 'Nama Pegawai', 'Unit Kerja', 'Jabatan', 'ID Pegawai', '2026-11-01', '2026-11-02']]);
        foreach ($rows as $index => $row) {
            array_splice($row, 4, 0, [(string) (Pegawai::query()->where('nik', $row[0])->value('id') ?? 999999)]);
            foreach ($row as $column => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 2], $value, is_string($value) && str_starts_with($value, '=') ? DataType::TYPE_FORMULA : DataType::TYPE_STRING);
                }
            }
        }
        ob_start();
        ($format === 'xls' ? new Xls($book) : new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent('jadwal.'.$format, $content);
    }

    public function test_bulk_options_include_employees_beyond_the_directory_page(): void
    {
        foreach (range(1, 12) as $index) {
            $this->employee('00'.$index);
        }
        Pegawai::query()->create(['nik' => 'NO-USER', 'nama' => 'Tanpa akun']);
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('assignments.data', 10)->has('bulkOptions.employees', 12));
        $book = app(ShiftAssignmentSpreadsheetService::class)->template($this->data());
        $this->assertSame(13, $book->getActiveSheet()->getHighestDataRow());
        $this->assertSame('001', $book->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $book->getActiveSheet()->getCell('A2')->getDataType());
        $this->assertSame('2026-11-01', $book->getActiveSheet()->getCell('F1')->getValue());
        $this->assertSame('KodeShift', $book->getActiveSheet()->getCell('F2')->getDataValidation()->getFormula1());
        $this->assertNotNull($book->getSheetByName('Referensi Shift'));
        $this->assertNotNull($book->getSheetByName('Petunjuk'));
        $book->disconnectWorksheets();
    }

    public function test_template_download_round_trips_and_imports_multiple_employees(): void
    {
        $first = $this->employee('001');
        $second = $this->employee('002');
        $response = $this->post(self::URL.'/template', $this->data())->assertOk()
            ->assertDownload('template-assign-shift-2026-11-01_2026-11-02.xlsx');
        $path = tempnam(sys_get_temp_dir(), 'shift-template-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $book->getActiveSheet()->setCellValue('F2', 'S'.$this->shift->id)->setCellValue('G3', 'S'.$this->shift->id);
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
            $file = UploadedFile::fake()->createWithContent('template.xlsx', file_get_contents($path));
            $this->post(self::URL.'/import', $this->data(['file' => $file]))->assertSessionHasNoErrors()
                ->assertSessionHas('success', '2 jadwal shift untuk 2 pegawai berhasil diimpor.');
        } finally {
            unlink($path);
        }
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $first->id, 'tanggal_presensi' => '2026-11-01', 'shift_id' => $this->shift->id, 'updated_by' => $this->admin->id]);
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $second->id, 'tanggal_presensi' => '2026-11-02', 'shift_id' => $this->shift->id]);
        $this->assertNull($first->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 2);
        $this->getJson(self::URL.'/'.$first->id.'/schedule?month=2026-11')->assertOk()
            ->assertJsonCount(30, 'days')
            ->assertJsonPath('days.0.source', 'scheduled')
            ->assertJsonPath('days.0.shift.id', $this->shift->id)
            ->assertJsonPath('days.1.source', 'default')
            ->assertJsonPath('days.1.shift', null);
    }

    public function test_directory_shows_the_imported_daily_shift_before_the_office_default(): void
    {
        $this->travelTo('2026-11-01 08:00:00');
        $office = Shift::query()->create(['nama' => 'Office', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00', 'libur_minggu' => true]);
        $night = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $off = Shift::query()->create(['nama' => 'DAYOFF', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        $employee = $this->employee('001', ['shift_id' => $office->id]);

        $this->post(self::URL.'/import', $this->data(['file' => $this->excel([
            [$employee->nik, '', '', '', 'S'.$night->id, 'S'.$off->id],
        ])]))->assertSessionHasNoErrors();

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignments.data.0.shift.nama', 'Office')
            ->where('assignments.data.0.today_schedule.date', '2026-11-01')
            ->where('assignments.data.0.today_schedule.source', 'scheduled')
            ->where('assignments.data.0.today_schedule.shift.nama', 'Malam')
            ->where('assignments.data.0.today_schedule.is_day_off', false));
        $record = app(AttendanceScheduleService::class)->clockInRecord($employee);
        $this->assertSame($night->id, $record->shift_id);

        $this->travelTo('2026-11-02 08:00:00');
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignments.data.0.today_schedule.source', 'scheduled')
            ->where('assignments.data.0.today_schedule.shift.nama', 'DAYOFF')
            ->where('assignments.data.0.today_schedule.is_day_off', true)
            ->where('assignments.data.0.today_schedule.day_off_reason', 'DAYOFF'));

        $this->travelTo('2026-11-03 08:00:00');
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('assignments.data.0.today_schedule.source', 'default')
            ->where('assignments.data.0.today_schedule.shift.nama', 'Office')
            ->where('assignments.data.0.today_schedule.is_day_off', false));
        $this->assertSame($office->id, $employee->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 2);
    }

    public function test_template_filters_by_unit_and_position_and_supports_explicit_selection(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD']);
        $position = Jabatan::query()->create(['kode' => 'PRW', 'nama' => 'Perawat', 'unit_kerja_id' => $unit->id]);
        $first = $this->employee('001', ['unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id]);
        $second = $this->employee('002', ['unit_kerja_id' => $unit->id]);
        $this->employee('003');
        $service = app(ShiftAssignmentSpreadsheetService::class);
        foreach ([
            ['selection' => 'filtered', 'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id],
            ['selection' => 'custom', 'pegawai_ids' => [$first->id]],
        ] as $selection) {
            $book = $service->template($this->data($selection));
            $this->assertSame(2, $book->getActiveSheet()->getHighestDataRow());
            $this->assertSame('001', $book->getActiveSheet()->getCell('A2')->getValue());
            $book->disconnectWorksheets();
        }
        $book = $service->template($this->data(['selection' => 'filtered', 'unit_kerja_id' => $unit->id]));
        $this->assertSame(3, $book->getActiveSheet()->getHighestDataRow());
        $book->disconnectWorksheets();
        $this->post(self::URL.'/import', $this->data(['selection' => 'custom', 'pegawai_ids' => [$first->id], 'file' => $this->excel([[$second->nik, '', '', '', 'S'.$this->shift->id]])]))
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_xls_and_xlsx_support_off_shift_and_preserve_blank_cells_and_other_dates(): void
    {
        $employee = $this->employee('00012', ['shift_id' => $this->shift->id]);
        $off = Shift::query()->create(['nama' => 'OFF', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        $existing = Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-02', 'shift_id' => $this->shift->id, 'notes' => 'Tetap']);
        foreach (['xlsx', 'xls'] as $format) {
            $this->post(self::URL.'/import', $this->data(['file' => $this->excel([[$employee->nik, '', '', '', 'S'.$off->id, null]], $format)]))
                ->assertSessionHasNoErrors();
        }
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-01', 'shift_id' => $off->id, 'is_day_off' => true]);
        $this->assertSame($this->shift->id, $employee->refresh()->shift_id);
        $this->assertSame('Tetap', $existing->refresh()->notes);
        $this->assertDatabaseCount('presensis', 2);
        $this->travelTo('2026-11-01 08:00:00');
        $record = app(AttendanceScheduleService::class)->clockInRecord($employee);
        $this->assertSame($off->id, $record->shift_id);
        $this->assertTrue(app(ShiftHolidayService::class)->isDayOff($employee, $record));
    }

    public function test_invalid_codes_unknown_employees_duplicates_formulas_and_empty_files_are_atomic(): void
    {
        $employee = $this->employee('001');
        $valid = [$employee->nik, '', '', '', 'S'.$this->shift->id];
        foreach ([
            [$valid, ['unknown', '', '', '', 'S'.$this->shift->id]],
            [[$employee->nik, '', '', '', 'INVALID']],
            [$valid, $valid],
            [[$employee->nik, '', '', '', '=1+1']],
            [[$employee->nik, '', '', '', null, null]],
        ] as $rows) {
            $this->post(self::URL.'/import', $this->data(['file' => $this->excel($rows)]))->assertSessionHasErrors('file');
            $this->assertDatabaseCount('presensis', 0);
        }
    }

    public function test_started_attendance_rejects_the_entire_import_and_preserves_evidence(): void
    {
        $first = $this->employee('001');
        $second = $this->employee('002');
        $record = Presensi::query()->create(['pegawai_id' => $second->id, 'shift_id' => $this->shift->id, 'tanggal_presensi' => '2026-11-01', 'clock_in_at' => '2026-11-01 07:01:00', 'notes' => 'Bukti']);
        $this->post(self::URL.'/import', $this->data(['file' => $this->excel([
            [$first->nik, '', '', '', 'S'.$this->shift->id],
            [$second->nik, '', '', '', 'S'.$this->shift->id],
        ])]))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('presensis', 1);
        $this->assertSame('Bukti', $record->refresh()->notes);
        $this->assertNotNull($record->clock_in_at);
    }

    public function test_period_header_selection_and_file_validation(): void
    {
        $this->employee('001');
        foreach ([
            ['date_from' => '2026-10-31'], ['date_to' => '2026-12-01'], ['date_to' => '2026-10-31'],
            ['selection' => 'filtered'], ['selection' => 'custom', 'pegawai_ids' => []], ['month' => 'invalid'],
        ] as $invalid) {
            $this->post(self::URL.'/template', $this->data($invalid))->assertSessionHasErrors();
        }
        $this->post(self::URL.'/import', $this->data(['file' => $this->excel([['001', '', '', '', 'S'.$this->shift->id]], 'xlsx', ['NIK', 'Nama Pegawai', 'Unit Kerja', 'Jabatan', 'ID Pegawai', '2026-10-01', '2026-10-02'])]))
            ->assertSessionHasErrors('file');
        $this->post(self::URL.'/import', $this->data())->assertSessionHasErrors('file');
        $this->post(self::URL.'/import', $this->data(['file' => UploadedFile::fake()->createWithContent('rusak.xlsx', 'invalid')]))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_template_and_import_require_update_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW]);
        $this->actingAs($viewer)->post(self::URL.'/template', $this->data())->assertForbidden();
        $this->post(self::URL.'/import', $this->data())->assertForbidden();
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->where('bulkOptions', null));
    }

    public function test_employees_without_nik_are_matched_by_their_template_identity(): void
    {
        $first = $this->employee('001');
        $second = $this->employee('002');
        foreach ([$first, $second] as $employee) {
            $employee->update(['nik' => null]);
        }
        $book = app(ShiftAssignmentSpreadsheetService::class)->template($this->data());
        $sheet = $book->getActiveSheet();
        $this->assertFalse($sheet->getColumnDimension('E')->getVisible());
        $sheet->setCellValue('F2', 'S'.$this->shift->id)->setCellValue('G3', 'S'.$this->shift->id);
        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = ob_get_clean();
        $book->disconnectWorksheets();
        $this->post(self::URL.'/import', $this->data(['file' => UploadedFile::fake()->createWithContent('tanpa-nik.xlsx', $content)]))
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 jadwal shift untuk 2 pegawai berhasil diimpor.');
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $first->id, 'tanggal_presensi' => '2026-11-01']);
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $second->id, 'tanggal_presensi' => '2026-11-02']);
    }

    public function test_active_leave_overtime_and_shift_changes_cannot_be_overwritten(): void
    {
        $first = $this->employee('001');
        $second = $this->employee('002');
        $identity = ['pegawai_id' => $second->id, 'user_id' => $second->user_id, 'nama_pegawai' => $second->nama, 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD', 'alasan' => 'Pengajuan'];
        $conflicts = [
            CutiRequest::query()->create([...$identity, 'tanggal_mulai' => '2026-10-31', 'tanggal_selesai' => '2026-11-01', 'status' => 'approved']),
            LemburRequest::query()->create([...$identity, 'shift_id' => $this->shift->id, 'nama_shift' => $this->shift->nama, 'tanggal_lembur' => '2026-11-01', 'shift_start_at' => '2026-11-01 07:00:00', 'shift_end_at' => '2026-11-01 15:00:00', 'duration_minutes' => 60, 'compensation' => 'money', 'status' => 'pending']),
            PerubahanShiftRequest::query()->create([...$identity, 'original_shift_id' => $this->shift->id, 'requested_shift_id' => $this->shift->id, 'tanggal_perubahan' => '2026-11-01', 'original_shift' => [], 'requested_shift' => [], 'status' => 'pending']),
        ];
        // Resolve one conflict at a time; the next active request must still reject the file.
        foreach ($conflicts as $conflict) {
            $this->post(self::URL.'/import', $this->data(['file' => $this->excel([
                [$first->nik, '', '', '', 'S'.$this->shift->id], [$second->nik, '', '', '', 'S'.$this->shift->id],
            ])]))->assertSessionHasErrors('file');
            $this->assertDatabaseCount('presensis', 0);
            $conflict->delete();
        }
    }

    public function test_existing_unstarted_schedule_is_updated_without_losing_notes(): void
    {
        $employee = $this->employee('001');
        $off = Shift::query()->create(['nama' => 'OFF', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        $record = Presensi::query()->create(['pegawai_id' => $employee->id, 'shift_id' => $off->id, 'is_day_off' => true, 'tanggal_presensi' => '2026-11-01', 'notes' => 'Catatan admin']);
        $this->post(self::URL.'/import', $this->data(['file' => $this->excel([[$employee->nik, '', '', '', 'S'.$this->shift->id]])]))->assertSessionHasNoErrors();
        $this->assertSame($this->shift->id, $record->refresh()->shift_id);
        $this->assertFalse($record->is_day_off);
        $this->assertSame('Catatan admin', $record->notes);
        $this->assertDatabaseCount('presensis', 1);
    }
}
