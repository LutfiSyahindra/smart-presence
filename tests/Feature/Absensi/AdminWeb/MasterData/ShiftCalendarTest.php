<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\HariLibur;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
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

    private function employee(string $name, ?Shift $shift = null): Pegawai
    {
        $employee = Pegawai::query()->create(['nama' => $name, 'nik' => fake()->unique()->numerify('CAL-####'), 'shift_id' => $shift?->id]);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        return $employee;
    }
}
