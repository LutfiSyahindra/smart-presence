<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shift $morning;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->morning = Shift::query()->create([
            'nama' => 'Shift Pagi',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $this->night = Shift::query()->create([
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ]);
    }

    public function test_page_requires_authentication_and_shift_view_permission(): void
    {
        $this->get('/absensi/admin-web/master-data/shift/assign')
            ->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/absensi/admin-web/master-data/shift/assign')
            ->assertForbidden();

        $user->givePermissionTo([
            AccessPermission::ADMIN_ACCESS,
            AccessPermission::SHIFT_VIEW,
        ]);

        $this->get('/absensi/admin-web/master-data/shift/assign')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/shift/Assign')
                ->where('can.assign', false));
    }

    public function test_index_only_lists_employees_with_users_and_exposes_assignment_data(): void
    {
        $assigned = $this->employeeWithUser('Siti Aminah', 'siti@example.test');
        $assigned->forceFill([
            'shift_id' => $this->morning->id,
            'shift_assigned_at' => now(),
            'shift_assigned_by' => $this->admin->id,
        ])->save();
        $this->employeeWithUser('Budi Santoso', 'budi@example.test');
        Pegawai::query()->create(['nik' => 'PEG-003', 'nama' => 'Tanpa Akun']);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/shift/assign?status=assigned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/shift/Assign')
                ->where('overview.total', 2)
                ->where('overview.assigned', 1)
                ->where('overview.unassigned', 1)
                ->where('assignments.total', 1)
                ->where('assignments.data.0.nama', 'Siti Aminah')
                ->where('assignments.data.0.user.email', 'siti@example.test')
                ->where('assignments.data.0.shift.nama', 'Shift Pagi')
                ->where('assignments.data.0.assigned_by', $this->admin->name)
                ->where('shifts.0.nama', 'Shift Pagi')
                ->where('filters.status', 'assigned')
                ->where('can.assign', true));
    }

    public function test_directory_uses_todays_schedule_and_respects_default_holidays_and_unassigned_employees(): void
    {
        $this->travelTo('2026-11-01 08:00:00');
        $this->morning->update(['libur_minggu' => true]);
        $employee = $this->employeeWithUser('A Default', 'default@example.test');
        $employee->update(['shift_id' => $this->morning->id]);
        foreach (['2026-10-31', '2026-11-02'] as $date) {
            Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => $date, 'shift_id' => $this->night->id]);
        }
        $this->employeeWithUser('B Tanpa Shift', 'unassigned@example.test');
        $manualOff = $this->employeeWithUser('C Libur', 'off@example.test');
        Presensi::query()->create(['pegawai_id' => $manualOff->id, 'tanggal_presensi' => '2026-11-01', 'shift_id' => $this->night->id, 'is_day_off' => true]);

        $this->actingAs($this->admin)->get('/absensi/admin-web/master-data/shift/assign')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('assignments.data.0.today_schedule.source', 'default')
                ->where('assignments.data.0.today_schedule.shift.id', $this->morning->id)
                ->where('assignments.data.0.today_schedule.is_day_off', true)
                ->where('assignments.data.0.today_schedule.day_off_reason', 'Libur Minggu')
                ->where('assignments.data.1.today_schedule.source', 'default')
                ->where('assignments.data.1.today_schedule.shift', null)
                ->where('assignments.data.1.today_schedule.is_day_off', false)
                ->where('assignments.data.2.today_schedule.source', 'scheduled')
                ->where('assignments.data.2.today_schedule.is_day_off', true));
    }

    public function test_admin_can_assign_shift_to_one_or_multiple_employees_and_clear_it(): void
    {
        $first = $this->employeeWithUser('Budi Santoso', 'budi@example.test');
        $second = $this->employeeWithUser('Siti Aminah', 'siti@example.test');

        $this->actingAs($this->admin)
            ->put('/absensi/admin-web/master-data/shift/assign', [
                'pegawai_ids' => [$first->id, $second->id],
                'shift_id' => $this->night->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Shift 2 pegawai berhasil diperbarui.');

        foreach ([$first, $second] as $pegawai) {
            $pegawai->refresh();
            $this->assertSame($this->night->id, $pegawai->shift_id);
            $this->assertSame($this->admin->id, $pegawai->shift_assigned_by);
            $this->assertNotNull($pegawai->shift_assigned_at);
        }

        $this->get('/absensi/admin-web/presensi/set-pegawai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.pegawai', fn ($pegawai) => collect($pegawai)
                    ->where('id', $first->id)
                    ->where('shift_id', $this->night->id)
                    ->isNotEmpty()));

        $this->put('/absensi/admin-web/master-data/shift/assign', [
            'pegawai_ids' => [$first->id],
            'shift_id' => null,
        ])->assertSessionHas('success', 'Shift 1 pegawai berhasil dikosongkan.');

        $first->refresh();
        $this->assertNull($first->shift_id);
        $this->assertNull($first->shift_assigned_by);
        $this->assertNull($first->shift_assigned_at);
        $this->assertSame($this->night->id, $second->refresh()->shift_id);
    }

    public function test_assignment_rejects_employee_without_user_and_requires_shift_update_permission(): void
    {
        $withoutUser = Pegawai::query()->create(['nik' => 'PEG-099', 'nama' => 'Tanpa Akun']);

        $this->actingAs($this->admin)
            ->put('/absensi/admin-web/master-data/shift/assign', [
                'pegawai_ids' => [$withoutUser->id],
                'shift_id' => $this->morning->id,
            ])
            ->assertSessionHasErrors(['pegawai_ids.0']);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo([
            AccessPermission::ADMIN_ACCESS,
            AccessPermission::SHIFT_VIEW,
        ]);
        $employee = $this->employeeWithUser('Pegawai Valid', 'valid@example.test');

        $this->actingAs($viewer)
            ->put('/absensi/admin-web/master-data/shift/assign', [
                'pegawai_ids' => [$employee->id],
                'shift_id' => $this->morning->id,
            ])
            ->assertForbidden();

        $this->assertNull($employee->refresh()->shift_id);
    }

    public function test_monthly_schedule_shows_daily_assignments_and_default_fallback_without_changing_the_employee(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $employee = $this->employeeWithUser('Pegawai Terjadwal', 'jadwal@example.test');
        $employee->update(['shift_id' => $this->morning->id]);
        $other = $this->employeeWithUser('Pegawai Lain', 'lain@example.test');
        $off = Shift::query()->create(['nama' => 'DAYOFF', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        foreach ([['2026-11-02', $this->morning->id], ['2026-12-02', $this->night->id], ['2026-12-03', $off->id]] as [$date, $shiftId]) {
            Presensi::query()->create(['pegawai_id' => $employee->id, 'tanggal_presensi' => $date, 'shift_id' => $shiftId, 'is_day_off' => $shiftId === $off->id]);
        }
        Presensi::query()->create(['pegawai_id' => $other->id, 'tanggal_presensi' => '2026-12-02', 'shift_id' => $this->morning->id]);

        $url = '/absensi/admin-web/master-data/shift/assign/'.$employee->id.'/schedule';
        $this->actingAs($this->admin)->getJson($url)->assertOk()
            ->assertJsonPath('month', '2026-12')
            ->assertJsonPath('default_shift.nama', 'Shift Pagi')
            ->assertJsonCount(31, 'days')
            ->assertJsonPath('days.0.date', '2026-12-01')
            ->assertJsonPath('days.0.source', 'default')
            ->assertJsonPath('days.0.shift.nama', 'Shift Pagi')
            ->assertJsonPath('days.1.source', 'scheduled')
            ->assertJsonPath('days.1.shift.nama', 'Shift Malam')
            ->assertJsonPath('days.2.shift.nama', 'DAYOFF')
            ->assertJsonPath('days.2.is_day_off', true)
            ->assertJsonPath('days.30.source', 'default');
        $this->getJson($url.'?month=2028-02')->assertOk()->assertJsonCount(29, 'days')
            ->assertJsonPath('days.28.date', '2028-02-29');
        $this->assertSame($this->morning->id, $employee->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 4);
    }

    public function test_monthly_schedule_respects_shift_holidays_and_an_unassigned_employee(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $employee = $this->employeeWithUser('Pegawai Default', 'default@example.test');
        $url = '/absensi/admin-web/master-data/shift/assign/'.$employee->id.'/schedule';
        $this->actingAs($this->admin)->getJson($url)->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('days.0.shift', null)
            ->assertJsonPath('days.0.is_day_off', false);
        $this->morning->update(['libur_minggu' => true]);
        $employee->update(['shift_id' => $this->morning->id]);
        $this->getJson($url.'?month=2026-11')->assertOk()
            ->assertJsonPath('days.0.date', '2026-11-01')
            ->assertJsonPath('days.0.is_day_off', true)
            ->assertJsonPath('days.1.is_day_off', false);
    }

    public function test_monthly_schedule_requires_view_permission_and_validates_the_month_and_employee(): void
    {
        $employee = $this->employeeWithUser('Pegawai Kalender', 'kalender@example.test');
        $url = '/absensi/admin-web/master-data/shift/assign/'.$employee->id.'/schedule';
        $this->get($url)->assertRedirect('/absensi/admin-web/login');
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson($url)->assertForbidden();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW]);
        $this->getJson($url.'?month=2026-12')->assertOk();
        foreach (['2026-13', '2026-12-01', 'invalid'] as $month) {
            $this->getJson($url.'?month='.$month)->assertUnprocessable()->assertJsonValidationErrors('month');
        }
        $withoutUser = Pegawai::query()->create(['nama' => 'Tanpa Akun', 'nik' => 'NO-CALENDAR']);
        $this->getJson('/absensi/admin-web/master-data/shift/assign/'.$withoutUser->id.'/schedule')->assertNotFound();
    }

    private function employeeWithUser(string $name, string $email): Pegawai
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);

        $pegawai = Pegawai::query()->create([
            'nik' => fake()->unique()->numerify('PEG-###'),
            'nama' => $name,
            'email' => $email,
        ]);

        $pegawai->forceFill(['user_id' => $user->id])->save();

        return $pegawai;
    }
}
