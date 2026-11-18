<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\CutiRequest;
use App\Models\HariLibur;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/master-data/shift/assign/calendar';

    private User $viewer;

    private Shift $morning;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW]);
        $this->morning = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->night = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
    }

    public function test_calendar_uses_daily_schedules_then_defaults_and_lists_all_employees_without_writing(): void
    {
        $employee = $this->employee('Aminah', $this->morning);
        $unassigned = $this->employee('Budi');
        $off = Shift::query()->create(['nama' => 'DAYOFF', 'is_day_off' => true]);
        $this->employee('Citra', $off);
        Pegawai::query()->create(['nama' => 'Tanpa akun', 'shift_id' => $this->morning->id]);
        for ($index = 0; $index < 10; $index++) {
            $this->employee('Pegawai '.$index, $this->morning);
        }
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-10', 'shift_id' => $this->night->id]);
        Presensi::query()->create(['pegawai_id' => $unassigned->id, 'tanggal_presensi' => '2026-11-11', 'shift_id' => $this->night->id]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-12-10', 'shift_id' => $off->id, 'is_day_off' => true]);

        $response = $this->actingAs($this->viewer)->getJson(self::URL.'?search=missing&status=unassigned&page=2&shift_id='.$off->id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('today', '2026-11-10')
            ->assertJsonPath('selected_date', '2026-11-10')
            ->assertJsonPath('total', 13)
            ->assertJsonCount(30, 'days')
            ->assertJsonCount(13, 'employees')
            ->assertJsonPath('days.9.working', 11)
            ->assertJsonPath('days.9.day_off', 1)
            ->assertJsonPath('days.9.unassigned', 1)
            ->assertJsonPath('days.10.working', 12)
            ->assertJsonPath('days.10.unassigned', 0)
            ->assertJsonPath('employees.0.nama', 'Aminah')
            ->assertJsonPath('employees.0.source', 'scheduled')
            ->assertJsonPath('employees.0.shift.nama', 'Malam')
            ->assertJsonPath('employees.0.shift.jam_masuk', '22:00')
            ->assertJsonPath('employees.0.shift.jam_keluar', '06:00')
            ->assertJsonPath('employees.1.shift', null)
            ->assertJsonPath('employees.2.is_day_off', true)
            ->assertJsonPath('employees.2.day_off_reason', 'DAYOFF');

        $this->assertCount(13, array_unique(array_column($response->json('employees'), 'id')));
        $this->getJson(self::URL.'?date=2026-11-11')->assertOk()
            ->assertJsonPath('employees.0.shift.nama', 'Pagi')
            ->assertJsonPath('employees.0.source', 'default')
            ->assertJsonPath('employees.1.shift.nama', 'Malam')
            ->assertJsonPath('employees.1.source', 'scheduled');
        $this->assertSame($this->morning->id, $employee->refresh()->shift_id);
        $this->assertNull($unassigned->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 3);
    }

    public function test_calendar_counts_weekend_national_holiday_and_explicit_off_correctly(): void
    {
        $this->morning->update(['libur_minggu' => true, 'libur_nasional' => true]);
        $employee = $this->employee('Aminah', $this->morning);
        $this->employee('Budi', $this->night);
        HariLibur::query()->create(['tanggal' => '2026-11-12', 'nama' => 'Libur pengujian', 'jenis' => HariLibur::NASIONAL]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-15', 'shift_id' => $this->night->id]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-16', 'shift_id' => $this->night->id, 'is_day_off' => true]);
        $this->actingAs($this->viewer)->getJson(self::URL.'?date=2026-11-12')->assertOk()
            ->assertJsonPath('days.0.working', 1)
            ->assertJsonPath('days.0.day_off', 1)
            ->assertJsonPath('days.11.working', 1)
            ->assertJsonPath('days.11.day_off', 1)
            ->assertJsonPath('employees.0.shift.nama', 'Pagi')
            ->assertJsonPath('employees.0.is_day_off', true)
            ->assertJsonPath('employees.0.day_off_reason', 'Libur pengujian')
            ->assertJsonPath('employees.1.is_day_off', false)
            ->assertJsonPath('days.14.working', 2)
            ->assertJsonPath('days.14.day_off', 0)
            ->assertJsonPath('days.15.working', 1)
            ->assertJsonPath('days.15.day_off', 1);
        $this->getJson(self::URL.'?date=2026-11-16')->assertOk()
            ->assertJsonPath('employees.0.is_day_off', true)
            ->assertJsonPath('employees.0.day_off_reason', 'Hari libur');
    }

    public function test_calendar_handles_empty_months_leap_years_and_selected_dates(): void
    {
        $this->actingAs($this->viewer)->getJson(self::URL)->assertOk()
            ->assertJsonCount(0, 'employees')->assertJsonPath('total', 0)
            ->assertJsonPath('days.0.working', 0)
            ->assertJsonPath('days.0.day_off', 0)
            ->assertJsonPath('days.0.unassigned', 0);
        $this->getJson(self::URL.'?month=2028-02')->assertOk()
            ->assertJsonCount(29, 'days')
            ->assertJsonPath('selected_date', '2028-02-01')
            ->assertJsonPath('days.28.date', '2028-02-29');
        $this->getJson(self::URL.'?date=2028-02-29')->assertOk()
            ->assertJsonPath('month', '2028-02')
            ->assertJsonPath('selected_date', '2028-02-29');
    }

    public function test_monthly_calendar_filters_all_employees_and_daily_totals_by_unit_without_writing(): void
    {
        $icu = UnitKerja::query()->create(['kode' => 'ICU', 'nama' => 'ICU']);
        $igd = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD']);
        $off = Shift::query()->create(['nama' => 'DAYOFF', 'is_day_off' => true]);
        $employee = $this->employee('Aminah', $this->morning);
        $employee->update(['unit_kerja_id' => $icu->id]);
        $unassigned = $this->employee('Budi');
        $unassigned->update(['unit_kerja_id' => $icu->id]);
        for ($index = 0; $index < 10; $index++) {
            $this->employee('Pegawai '.$index, $this->morning)->update(['unit_kerja_id' => $icu->id]);
        }
        $outside = $this->employee('Unit lain', $this->night);
        $outside->update(['unit_kerja_id' => $igd->id]);
        $this->employee('Tanpa unit', $this->night);
        Pegawai::query()->create(['nama' => 'Tanpa akun', 'unit_kerja_id' => $icu->id, 'shift_id' => $this->morning->id]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-10', 'shift_id' => $this->night->id]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-11', 'shift_id' => $off->id, 'is_day_off' => true]);
        Presensi::query()->create(['pegawai_id' => $outside->id, 'tanggal_presensi' => '2026-11-10', 'shift_id' => $off->id, 'is_day_off' => true]);

        $response = $this->actingAs($this->viewer)->getJson(self::URL.'?view=monthly&unit_kerja_id='.$icu->id.'&search=missing&page=2')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('view', 'monthly')->assertJsonPath('unit_kerja_id', $icu->id)
            ->assertJsonCount(2, 'units')->assertJsonPath('units.0.nama', 'ICU')->assertJsonPath('units.1.nama', 'IGD')
            ->assertJsonPath('total', 12)->assertJsonCount(12, 'employees')
            ->assertJsonPath('days.9.working', 11)->assertJsonPath('days.9.day_off', 0)->assertJsonPath('days.9.unassigned', 1)
            ->assertJsonPath('days.10.working', 10)->assertJsonPath('days.10.day_off', 1)
            ->assertJsonPath('employees.0.unit_kerja_id', $icu->id)
            ->assertJsonCount(30, 'employees.0.schedule')
            ->assertJsonPath('employees.0.schedule.0.date', '2026-11-01')
            ->assertJsonPath('employees.0.schedule.0.shift_id', $this->morning->id)
            ->assertJsonPath('employees.0.schedule.0.source', 'default')
            ->assertJsonPath('employees.0.schedule.9.shift_id', $this->night->id)
            ->assertJsonPath('employees.0.schedule.9.source', 'scheduled')
            ->assertJsonPath('employees.0.schedule.10.shift_id', $off->id)
            ->assertJsonPath('employees.0.schedule.10.is_day_off', true)
            ->assertJsonPath('employees.0.schedule.10.day_off_reason', 'DAYOFF')
            ->assertJsonPath('employees.1.schedule.0.shift_id', null)
            ->assertJsonPath('employees.1.schedule.0.is_day_off', false);
        $this->assertCount(12, array_unique(array_column($response->json('employees'), 'id')));
        $this->assertEqualsCanonicalizing([$this->morning->id, $this->night->id, $off->id], array_column($response->json('shifts'), 'id'));
        $this->getJson(self::URL.'?unit_kerja_id='.$igd->id)->assertOk()
            ->assertJsonPath('view', 'daily')->assertJsonPath('total', 1)
            ->assertJsonPath('days.9.day_off', 1)->assertJsonPath('employees.0.nama', 'Unit lain')
            ->assertJsonMissingPath('employees.0.schedule');
        $this->assertSame($this->morning->id, $employee->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 3);
    }

    public function test_monthly_calendar_supports_all_units_missing_units_empty_units_and_holiday_rules(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'EMPTY', 'nama' => 'Unit kosong']);
        $this->morning->update(['libur_minggu' => true, 'libur_nasional' => true]);
        $employee = $this->employee('Aminah', $this->morning);
        HariLibur::query()->create(['tanggal' => '2028-02-03', 'nama' => 'Libur pengujian', 'jenis' => HariLibur::NASIONAL]);
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2028-02-06', 'shift_id' => $this->night->id]);

        $this->actingAs($this->viewer)->getJson(self::URL.'?view=monthly&month=2028-02')
            ->assertOk()->assertJsonPath('unit_kerja_id', null)->assertJsonPath('total', 1)
            ->assertJsonCount(29, 'employees.0.schedule')
            ->assertJsonPath('employees.0.schedule.2.is_day_off', true)
            ->assertJsonPath('employees.0.schedule.2.day_off_reason', 'Libur pengujian')
            ->assertJsonPath('employees.0.schedule.5.is_day_off', false)
            ->assertJsonPath('employees.0.schedule.5.source', 'scheduled')
            ->assertJsonPath('employees.0.schedule.12.is_day_off', true)
            ->assertJsonPath('employees.0.schedule.28.date', '2028-02-29');
        $this->getJson(self::URL.'?view=monthly&unit_kerja_id=unassigned')->assertOk()
            ->assertJsonPath('unit_kerja_id', 'unassigned')->assertJsonPath('total', 1)
            ->assertJsonPath('employees.0.unit_kerja_id', null);
        $this->getJson(self::URL.'?view=monthly&unit_kerja_id='.$unit->id)->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'employees')->assertJsonCount(30, 'days')
            ->assertJsonPath('days.9.working', 0)->assertJsonPath('days.9.day_off', 0)->assertJsonPath('days.9.unassigned', 0);
        foreach (['missing', '-1', '1.5', '999999', 'unassigned-extra'] as $unitId) {
            $this->getJson(self::URL.'?unit_kerja_id='.$unitId)->assertUnprocessable()->assertJsonValidationErrors('unit_kerja_id');
        }
        $this->getJson(self::URL.'?view=invalid')->assertUnprocessable()->assertJsonValidationErrors('view');
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_calendar_requires_view_permission_and_valid_month_and_date(): void
    {
        $this->get(self::URL)->assertRedirect('/absensi/admin-web/login');
        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->viewer);
        foreach (['2026-13', '2026-11-10', 'invalid'] as $month) {
            $this->getJson(self::URL.'?month='.$month)->assertUnprocessable()->assertJsonValidationErrors('month');
        }
        foreach (['2026-02-30', '2026-11', 'invalid'] as $date) {
            $this->getJson(self::URL.'?date='.$date)->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->getJson(self::URL.'?month=2026-11&date=2026-12-01')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_approved_annual_leave_overrides_default_and_scheduled_shift_display_and_totals(): void
    {
        $employee = $this->employee('Aminah', $this->morning);
        $this->employee('Budi', $this->night);
        $leave = $this->leave($employee, '2026-10-30', '2026-11-11');
        Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-10', 'shift_id' => $this->night->id]);

        $this->actingAs($this->viewer)->getJson(self::URL.'?view=monthly')->assertOk()
            ->assertJsonPath('employees.0.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.0.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.9.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.9.shift_id', $this->night->id)
            ->assertJsonPath('employees.0.schedule.10.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.11.leave_name', null)
            ->assertJsonPath('employees.1.leave_name', null)
            ->assertJsonPath('days.9.on_leave', 1)->assertJsonPath('days.9.working', 1)
            ->assertJsonPath('days.9.day_off', 0)->assertJsonPath('days.9.unassigned', 0)
            ->assertJsonPath('days.11.on_leave', 0)->assertJsonPath('days.11.working', 2);
        $this->getJson(self::URL.'?date=2026-11-10')->assertOk()
            ->assertJsonPath('employees.0.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('days.9.on_leave', 1)->assertJsonPath('days.9.working', 1);
        $this->getJson('/absensi/admin-web/master-data/shift/assign/'.$employee->id.'/schedule?month=2026-11')->assertOk()
            ->assertJsonPath('days.9.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('days.9.locked_reason', 'Tanggal ini memiliki cuti yang disetujui.')
            ->assertJsonPath('days.11.leave_name', null)
            ->assertJsonPath('days.11.locked_reason', null);

        $leave->update(['status' => 'cancelled']);
        $this->getJson(self::URL.'?view=monthly')->assertOk()
            ->assertJsonPath('employees.0.leave_name', null)
            ->assertJsonPath('employees.0.schedule.9.leave_name', null)
            ->assertJsonPath('days.9.on_leave', 0)->assertJsonPath('days.9.working', 2);
        $this->assertSame($this->morning->id, $employee->refresh()->shift_id);
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $employee->id, 'tanggal_presensi' => '2026-11-10', 'shift_id' => $this->night->id]);
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_pending_rejected_and_cancelled_leave_do_not_replace_shifts(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->leave($this->employee($status, $this->morning), '2026-11-10', '2026-11-10', ['status' => $status]);
        }
        $response = $this->actingAs($this->viewer)->getJson(self::URL.'?view=monthly')->assertOk()
            ->assertJsonPath('days.9.on_leave', 0)->assertJsonPath('days.9.working', 3);
        foreach ($response->json('employees') as $employee) {
            $this->assertNull($employee['leave_name']);
            $this->assertNull($employee['schedule'][9]['leave_name']);
            $this->assertSame($this->morning->id, $employee['schedule'][9]['shift_id']);
        }
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_leave_counts_respect_unit_month_boundaries_and_employees_without_a_shift(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'CUTI', 'nama' => 'Unit cuti']);
        $employee = $this->employee('Aminah');
        $employee->update(['unit_kerja_id' => $unit->id]);
        $this->leave($employee, '2026-11-30', '2026-12-02', ['nama_cuti' => null]);
        $this->leave($this->employee('Budi', $this->night), '2026-11-30', '2026-12-02', ['nama_cuti' => 'Cuti Khusus', 'is_tahunan' => false]);

        $this->actingAs($this->viewer)->getJson(self::URL.'?view=monthly&month=2026-11&unit_kerja_id='.$unit->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('days.29.on_leave', 1)
            ->assertJsonPath('days.29.unassigned', 0)->assertJsonPath('days.29.working', 0)
            ->assertJsonPath('employees.0.schedule.29.leave_name', 'Cuti Tahunan');
        $this->getJson(self::URL.'?view=monthly&month=2026-12&unit_kerja_id='.$unit->id)->assertOk()
            ->assertJsonPath('days.0.on_leave', 1)->assertJsonPath('days.1.on_leave', 1)
            ->assertJsonPath('days.2.on_leave', 0)->assertJsonPath('days.2.unassigned', 1)
            ->assertJsonPath('employees.0.schedule.0.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.1.leave_name', 'Cuti Tahunan')
            ->assertJsonPath('employees.0.schedule.2.leave_name', null);
        $this->getJson(self::URL.'?view=monthly&month=2026-12&unit_kerja_id=unassigned')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('employees.0.schedule.0.leave_name', 'Cuti Khusus');
        $this->assertDatabaseCount('presensis', 0);
    }

    private function leave(Pegawai $employee, string $from, string $to, array $attributes = []): CutiRequest
    {
        return CutiRequest::query()->create([
            'pegawai_id' => $employee->id, 'user_id' => $employee->user_id,
            'nama_pegawai' => $employee->nama, 'nama_jabatan' => 'Staff', 'nama_unit' => 'SIRS', 'alasan' => 'Keperluan',
            'tanggal_mulai' => $from, 'tanggal_selesai' => $to,
            'nama_cuti' => 'Cuti Tahunan', 'is_tahunan' => true, 'status' => 'approved',
            ...$attributes,
        ]);
    }

    private function employee(string $name, ?Shift $shift = null): Pegawai
    {
        $employee = Pegawai::query()->create(['nama' => $name, 'nik' => fake()->unique()->numerify('CAL-####'), 'shift_id' => $shift?->id]);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        return $employee;
    }
}
