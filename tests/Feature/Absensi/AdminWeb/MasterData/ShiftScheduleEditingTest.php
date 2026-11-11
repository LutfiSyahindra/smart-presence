<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftScheduleEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Pegawai $employee;

    private Shift $office;

    private Shift $night;

    private Shift $off;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->office = Shift::query()->create(['nama' => 'Office', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->night = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '21:00', 'jam_keluar' => '07:00']);
        $this->off = Shift::query()->create(['nama' => 'DAYOFF', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        $this->employee = Pegawai::query()->create(['nama' => 'Pegawai Jadwal', 'nik' => 'JADWAL-001', 'shift_id' => $this->office->id]);
        $this->employee->forceFill(['user_id' => User::factory()->create()->id, 'shift_assigned_at' => now()->subDay(), 'shift_assigned_by' => $this->admin->id])->save();
        $this->url = '/absensi/admin-web/master-data/shift/assign/'.$this->employee->id.'/schedule';
    }

    public function test_edit_creates_only_the_selected_date_and_preserves_default_and_other_dates(): void
    {
        $assignedAt = $this->employee->shift_assigned_at->toDateTimeString();
        $existing = Presensi::query()->create(['pegawai_id' => $this->employee->id, 'tanggal_presensi' => '2026-12-05', 'shift_id' => $this->off->id, 'is_day_off' => true, 'notes' => 'Tetap']);
        $this->actingAs($this->admin)->putJson($this->url, ['date' => '2026-12-04', 'shift_id' => $this->night->id])->assertOk()
            ->assertJsonPath('schedule.month', '2026-12')
            ->assertJsonPath('schedule.days.3.source', 'scheduled')
            ->assertJsonPath('schedule.days.3.shift.nama', 'Malam')
            ->assertJsonPath('schedule.default_shift.nama', 'Office');
        $this->assertDatabaseHas('presensis', ['pegawai_id' => $this->employee->id, 'tanggal_presensi' => '2026-12-04', 'shift_id' => $this->night->id, 'updated_by' => $this->admin->id]);
        $this->assertSame($this->office->id, $this->employee->refresh()->shift_id);
        $this->assertSame($assignedAt, $this->employee->shift_assigned_at->toDateTimeString());
        $this->assertSame($this->admin->id, $this->employee->shift_assigned_by);
        $this->assertSame($this->off->id, $existing->refresh()->shift_id);
        $this->assertSame('Tetap', $existing->notes);
        $this->assertDatabaseCount('presensis', 2);
    }

    public function test_existing_schedule_can_change_to_off_and_back_without_losing_notes(): void
    {
        $record = Presensi::query()->create(['pegawai_id' => $this->employee->id, 'tanggal_presensi' => '2026-12-02', 'shift_id' => $this->night->id, 'notes' => 'Catatan admin']);
        $this->actingAs($this->admin)->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->off->id])->assertOk()
            ->assertJsonPath('schedule.days.1.shift.nama', 'DAYOFF')
            ->assertJsonPath('schedule.days.1.is_day_off', true);
        $this->assertTrue($record->refresh()->is_day_off);
        $this->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertOk()
            ->assertJsonPath('schedule.days.1.shift.nama', 'Malam')
            ->assertJsonPath('schedule.days.1.is_day_off', false);
        $this->assertSame('Catatan admin', $record->refresh()->notes);
        $this->assertSame($this->admin->id, $record->updated_by);
        $this->assertFalse($record->is_day_off);
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_clock_in_and_out_lock_the_date_and_are_rechecked_after_the_calendar_was_loaded(): void
    {
        $record = Presensi::query()->create(['pegawai_id' => $this->employee->id, 'tanggal_presensi' => '2026-12-02', 'shift_id' => $this->office->id, 'notes' => 'Bukti']);
        $this->actingAs($this->admin)->getJson($this->url.'?month=2026-12')->assertOk()->assertJsonPath('days.1.locked_reason', null);
        foreach (['clock_in_at', 'clock_out_at'] as $field) {
            $record->update(['clock_in_at' => null, 'clock_out_at' => null, $field => '2026-12-02 08:00:00']);
            $this->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertUnprocessable()->assertJsonValidationErrors('date');
            $this->getJson($this->url.'?month=2026-12')->assertOk()->assertJsonPath('days.1.locked_reason', 'Tanggal ini sudah memiliki clock in/out.');
            $this->assertSame($this->office->id, $record->refresh()->shift_id);
            $this->assertSame('Bukti', $record->notes);
            $this->assertNotNull($record->{$field});
        }
    }

    public function test_active_leave_overtime_and_shift_change_requests_block_edits_until_resolved(): void
    {
        $identity = ['pegawai_id' => $this->employee->id, 'user_id' => $this->employee->user_id, 'nama_pegawai' => $this->employee->nama, 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD', 'alasan' => 'Pengajuan'];
        $conflicts = [
            CutiRequest::query()->create([...$identity, 'tanggal_mulai' => '2026-11-30', 'tanggal_selesai' => '2026-12-02', 'status' => 'approved']),
            LemburRequest::query()->create([...$identity, 'shift_id' => $this->office->id, 'nama_shift' => $this->office->nama, 'tanggal_lembur' => '2026-12-02', 'shift_start_at' => '2026-12-02 08:00:00', 'shift_end_at' => '2026-12-02 16:00:00', 'duration_minutes' => 60, 'compensation' => 'money', 'status' => 'approved']),
            PerubahanShiftRequest::query()->create([...$identity, 'original_shift_id' => $this->office->id, 'requested_shift_id' => $this->night->id, 'tanggal_perubahan' => '2026-12-02', 'original_shift' => [], 'requested_shift' => [], 'status' => 'pending']),
        ];
        $this->actingAs($this->admin);
        foreach ($conflicts as $conflict) {
            $this->getJson($this->url.'?month=2026-12')->assertOk()->assertJsonPath('days.1.locked_reason', fn ($reason) => is_string($reason) && $reason !== '');
            $this->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertUnprocessable()->assertJsonValidationErrors('date');
            $this->assertDatabaseCount('presensis', 0);
            $conflict->update(['status' => 'rejected']);
        }
        $this->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertOk()->assertJsonPath('schedule.days.1.locked_reason', null);
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_edit_permission_input_validation_and_employee_account_are_required(): void
    {
        $this->put($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertRedirect('/absensi/admin-web/login');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW]);
        $this->actingAs($viewer)->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertForbidden();
        $this->actingAs($this->admin);
        foreach ([['date' => '2026-02-30', 'shift_id' => $this->night->id], ['date' => '2026-12-02', 'shift_id' => 999999], ['date' => '2026-12-02', 'shift_id' => null], ['date' => 'invalid', 'shift_id' => '1.1']] as $data) {
            $this->putJson($this->url, $data)->assertUnprocessable();
        }
        $withoutUser = Pegawai::query()->create(['nama' => 'Tanpa Akun', 'nik' => 'NO-EDIT']);
        $this->putJson('/absensi/admin-web/master-data/shift/assign/'.$withoutUser->id.'/schedule', ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertNotFound();
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_attendance_and_requests_on_other_dates_or_employees_do_not_block_the_edit(): void
    {
        $other = Pegawai::query()->create(['nama' => 'Pegawai Lain', 'nik' => 'OTHER-EDIT']);
        $record = Presensi::query()->create(['pegawai_id' => $other->id, 'tanggal_presensi' => '2026-12-02', 'shift_id' => $this->office->id, 'clock_in_at' => '2026-12-02 08:00:00']);
        Presensi::query()->create(['pegawai_id' => $this->employee->id, 'tanggal_presensi' => '2026-12-03', 'shift_id' => $this->office->id, 'clock_in_at' => '2026-12-03 08:00:00']);
        CutiRequest::query()->create(['pegawai_id' => $other->id, 'user_id' => $this->admin->id, 'nama_pegawai' => $other->nama, 'nama_jabatan' => 'Staff', 'nama_unit' => 'SIRS', 'alasan' => 'Cuti', 'tanggal_mulai' => '2026-12-02', 'tanggal_selesai' => '2026-12-02', 'status' => 'approved']);
        $this->actingAs($this->admin)->putJson($this->url, ['date' => '2026-12-02', 'shift_id' => $this->night->id])->assertOk()
            ->assertJsonPath('schedule.days.1.shift.nama', 'Malam')
            ->assertJsonPath('schedule.days.1.locked_reason', null);
        $this->assertSame($this->office->id, $record->refresh()->shift_id);
        $this->assertDatabaseCount('presensis', 3);
    }
}
