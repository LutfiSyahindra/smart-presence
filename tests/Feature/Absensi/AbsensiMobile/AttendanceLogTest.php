<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\CutiRequest;
use App\Models\HariLibur;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pegawai $pegawai;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-21 12:00:00');
        $this->seed(AuthorizationSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $this->shift = Shift::query()->create([
            'nama' => 'Shift Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00',
            'libur_sabtu' => true, 'libur_minggu' => true, 'libur_nasional' => true,
        ]);
        $this->pegawai = Pegawai::query()->create([
            'nama' => 'Pegawai Log', 'shift_id' => $this->shift->id, 'tanggal_mulai_bekerja' => '2026-10-01',
        ]);
        $this->pegawai->forceFill(['user_id' => $this->user->id, 'shift_assigned_at' => '2026-10-01 08:00:00'])->save();
    }

    public function test_monthly_log_summarizes_real_attendance_and_scheduled_days(): void
    {
        $this->record('2026-10-01', ['clock_in_at' => '2026-10-01 08:16', 'clock_out_at' => '2026-10-01 16:29']);
        $this->record('2026-10-02', ['clock_in_at' => '2026-10-02 08:00', 'clock_out_at' => '2026-10-02 15:30']);
        $this->record('2026-10-05', ['clock_in_at' => '2026-10-05 07:58', 'clock_out_at' => '2026-10-05 16:00', 'notes' => 'Catatan pegawai.']);
        $this->record('2026-10-06', ['clock_in_at' => '2026-10-06 08:00']);
        $this->record('2026-10-07', ['clock_out_at' => '2026-10-07 16:00']);
        $this->record('2026-10-08', ['clock_in_at' => '2026-10-08 08:00', 'clock_out_at' => '2026-10-08 07:00']);
        $this->record('2026-10-09', ['is_day_off' => true]);
        $this->leave('2026-10-12', '2026-10-13', 'approved');
        $this->leave('2026-10-14', '2026-10-14', 'pending');
        HariLibur::query()->create(['tanggal' => '2026-10-20', 'nama' => 'Libur Uji', 'jenis' => HariLibur::NASIONAL]);

        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/AttendanceLog')
            ->where('month', '2026-10')->where('monthLabel', 'Oktober 2026')
            ->has('days', 31)->where('days.0.date', '2026-10-01')->where('days.30.date', '2026-10-31')
            ->where('days.0.clock_in', '08:16')->where('days.0.late_minutes', 16)
            ->where('days.1.early_departure_minutes', 30)->where('days.4.duration', '8 jam 2 menit')
            ->where('days.4.notes', 'Catatan pegawai.')->where('days.11.status', 'leave')
            ->where('days.19.notes', 'Libur Uji')->where('days.20.status', 'pending')->where('days.21.status', 'upcoming')
            ->where('summary', [
                'late' => 1, 'early_departure' => 1, 'absent' => 4, 'no_clock_in' => 1, 'no_clock_out' => 1,
                'day_off' => 11, 'on_time' => 3, 'leave' => 2, 'invalid' => 1, 'upcoming' => 7,
            ]));
        $this->assertDatabaseCount('presensis', 7);
    }

    public function test_weekends_are_labeled_as_libur_for_past_and_future_dates_without_creating_records(): void
    {
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.2.status', 'day_off')->where('days.2.status_label', 'Libur')
            ->where('days.2.shift_name', 'Libur Sabtu')->where('days.2.day_off_type', 'weekend')
            ->where('days.2.day_off_reason', 'Libur Sabtu')->where('days.2.schedule', null)
            ->where('days.2.clock_in', null)->where('days.2.clock_out', null)
            ->where('days.2.flags.absent', false)->where('days.2.flags.no_clock_in', false)->where('days.2.flags.no_clock_out', false)
            ->where('days.3.status_label', 'Libur')->where('days.3.shift_name', 'Libur Minggu')
            ->where('days.23.status_label', 'Libur')->where('days.23.flags.upcoming', false)
            ->where('days.24.status_label', 'Libur')->where('summary.day_off', 9));

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_national_holidays_have_names_and_take_precedence_over_weekends_and_collective_leave(): void
    {
        $this->shift->update(['libur_cuti_bersama' => true]);
        foreach ([
            ['2026-10-01', 'Libur Nasional Uji', HariLibur::NASIONAL],
            ['2026-10-02', 'Cuti Bersama Uji', HariLibur::CUTI_BERSAMA],
            ['2026-10-03', 'Cuti Bersama Akhir Pekan', HariLibur::CUTI_BERSAMA],
            ['2026-10-03', 'Libur Nasional Akhir Pekan', HariLibur::NASIONAL],
            ['2026-10-24', 'Libur Nasional Mendatang', HariLibur::NASIONAL],
            ['2027-10-05', 'Libur Tahun Depan', HariLibur::NASIONAL],
        ] as [$date, $name, $type]) {
            HariLibur::query()->create(['tanggal' => $date, 'nama' => $name, 'jenis' => $type]);
        }
        $this->record('2026-10-01', ['is_day_off' => true, 'notes' => 'Catatan libur dari admin.']);

        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.0.status', 'day_off')->where('days.0.status_label', 'Cuti nasional')
            ->where('days.0.day_off_type', HariLibur::NASIONAL)->where('days.0.tone', 'holiday')
            ->where('days.0.shift_name', 'Libur Nasional Uji')->where('days.0.day_off_reason', 'Libur Nasional Uji')
            ->where('days.0.notes', 'Catatan libur dari admin.')->where('days.0.flags.leave', false)
            ->where('days.0.flags.absent', false)->where('days.0.flags.no_clock_in', false)->where('days.0.flags.no_clock_out', false)
            ->where('days.1.status_label', 'Cuti bersama')->where('days.1.day_off_type', HariLibur::CUTI_BERSAMA)
            ->where('days.1.shift_name', 'Cuti Bersama Uji')->where('days.1.flags.day_off', true)
            ->where('days.2.status_label', 'Cuti nasional')->where('days.2.shift_name', 'Libur Nasional Akhir Pekan')
            ->where('days.4.status', 'absent')->where('days.4.day_off_type', null)
            ->where('days.23.status_label', 'Cuti nasional')->where('days.23.flags.upcoming', false)
            ->where('summary.day_off', 11)->where('summary.leave', 0));

        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_holiday_labels_follow_only_the_employees_enabled_shift_categories(): void
    {
        $this->shift->update(['libur_sabtu' => false, 'libur_minggu' => false, 'libur_nasional' => false]);
        HariLibur::query()->create(['tanggal' => '2026-10-01', 'nama' => 'Libur Nasional Uji', 'jenis' => HariLibur::NASIONAL]);
        HariLibur::query()->create(['tanggal' => '2026-10-02', 'nama' => 'Cuti Bersama Uji', 'jenis' => HariLibur::CUTI_BERSAMA]);
        HariLibur::query()->create(['tanggal' => '2026-10-03', 'nama' => 'Libur Akhir Pekan', 'jenis' => HariLibur::NASIONAL]);

        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.0.status', 'absent')->where('days.0.day_off_type', null)
            ->where('days.1.status', 'absent')->where('days.2.status', 'absent')->where('days.3.status', 'absent')
            ->where('days.23.status', 'upcoming')->where('summary.day_off', 0));

        $this->shift->update(['libur_sabtu' => true]);
        $this->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.2.status_label', 'Libur')->where('days.2.day_off_type', 'weekend')
            ->where('days.2.shift_name', 'Libur Sabtu')->where('days.3.status', 'absent'));
    }

    public function test_logs_are_private_and_selected_month_handles_leap_years(): void
    {
        $other = Pegawai::query()->create(['nama' => 'Pegawai Lain', 'shift_id' => $this->shift->id]);
        $this->record('2024-02-01', ['clock_in_at' => '2024-02-01 08:00', 'clock_out_at' => '2024-02-01 16:00']);
        $this->record('2024-02-02', ['pegawai_id' => $other->id, 'clock_in_at' => '2024-02-02 08:00']);
        $this->record('2026-10-01', ['clock_in_at' => '2026-10-01 08:00']);
        $this->leave('2024-02-02', '2024-02-02', 'approved', $other->id);
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log?month=2024-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('month', '2024-02')->has('days', 29)->where('days.28.date', '2024-02-29')
            ->where('days.0.clock_in', '08:00')->where('days.1.clock_in', null)
            ->where('summary.on_time', 1)->where('summary.leave', 0)->where('summary.absent', 0));
    }

    public function test_overnight_shift_is_not_incomplete_until_its_scheduled_end(): void
    {
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->record('2026-10-20', ['clock_in_at' => '2026-10-20 22:00']);
        $this->travelTo('2026-10-21 05:00');
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.19.status', 'working')->where('summary.no_clock_out', 0));
        $this->travelTo('2026-10-21 07:00');
        $this->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.19.status', 'no_clock_out')->where('summary.no_clock_out', 1));
        $this->pegawai->presensis()->whereDate('tanggal_presensi', '2026-10-20')->update(['clock_out_at' => '2026-10-21 06:00']);
        $this->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.19.status', 'present')->where('days.19.duration', '8 jam 0 menit')
            ->where('days.19.clock_out_date_label', '21 Okt 2026, 06:00')->where('summary.invalid', 0));
    }

    public function test_current_shift_does_not_create_absences_before_employment_or_assignment(): void
    {
        $this->pegawai->forceFill(['tanggal_mulai_bekerja' => '2026-10-10', 'shift_assigned_at' => '2026-10-19 08:00'])->save();
        $this->record('2026-10-01', ['clock_in_at' => '2026-10-01 08:00', 'clock_out_at' => '2026-10-01 16:00']);
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.0.status', 'present')->where('days.1.status', 'unscheduled')->where('days.11.status', 'unscheduled')
            ->where('days.2.status', 'unscheduled')->where('days.2.day_off_type', null)
            ->where('days.18.status', 'absent')->where('summary.absent', 2));
    }

    public function test_started_records_keep_their_shift_even_when_current_shift_or_leave_changes(): void
    {
        $this->record('2026-10-03', ['clock_in_at' => '2026-10-03 08:00', 'clock_out_at' => '2026-10-03 16:00']);
        $this->leave('2026-10-03', '2026-10-03', 'approved');
        HariLibur::query()->create(['tanggal' => '2026-10-03', 'nama' => 'Libur Nasional Uji', 'jenis' => HariLibur::NASIONAL]);
        $differentShift = Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->pegawai->update(['shift_id' => $differentShift->id]);
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.2.shift_name', 'Shift Pagi')->where('days.2.status', 'present')
            ->where('days.2.clock_in', '08:00')->where('days.2.clock_out', '16:00')->where('days.2.day_off_type', null)
            ->where('days.2.flags.leave', false)->where('days.2.flags.day_off', false)->where('days.2.late_minutes', 0));
    }

    public function test_missing_employee_and_missing_shift_have_clear_states(): void
    {
        $this->pegawai->update(['shift_id' => null]);
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('days', 31)->where('days.0.status', 'unscheduled')->where('summary.absent', 0)->where('summary.upcoming', 0));
        $this->pegawai->forceFill(['user_id' => null])->save();
        $this->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('days', 0)->where('unavailableReason', fn ($reason) => is_string($reason) && str_contains($reason, 'pegawai')));
    }

    public function test_approved_leave_takes_precedence_over_holidays_as_on_the_attendance_page(): void
    {
        $this->leave('2026-10-03', '2026-10-04', 'approved');
        $this->leave('2026-10-05', '2026-10-05', 'rejected');
        HariLibur::query()->create(['tanggal' => '2026-10-03', 'nama' => 'Libur Nasional Uji', 'jenis' => HariLibur::NASIONAL]);
        $this->actingAs($this->user)->get('/absensi/mobile/presensi/log')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('days.2.status', 'leave')->where('days.3.status', 'leave')->where('days.4.status', 'absent')
            ->where('days.2.status_label', 'Cuti')->where('days.2.day_off_type', null)->where('days.2.flags.day_off', false)
            ->where('summary.leave', 2)->where('summary.day_off', 7));
    }

    public function test_invalid_month_is_rejected_and_mobile_access_is_required(): void
    {
        $this->get('/absensi/mobile/presensi/log')->assertRedirect('/login');
        $this->actingAs($this->user);
        foreach (['2026-13', 'not-a-month', '1899-12', '2101-01'] as $month) {
            $this->getJson('/absensi/mobile/presensi/log?month='.$month)->assertUnprocessable()->assertJsonValidationErrors('month');
        }
        $this->getJson('/absensi/mobile/presensi/log?month[]=2026-10')->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->user->removeRole('pegawai');
        $this->get('/absensi/mobile/presensi/log')->assertForbidden();
    }

    private function record(string $date, array $attributes = []): Presensi
    {
        return Presensi::query()->create(array_replace([
            'pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id, 'tanggal_presensi' => $date,
        ], $attributes));
    }

    private function leave(string $start, string $end, string $status, ?int $pegawaiId = null): void
    {
        CutiRequest::query()->create([
            'user_id' => $this->user->id, 'pegawai_id' => $pegawaiId ?? $this->pegawai->id,
            'nama_pegawai' => 'Pegawai Log', 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD', 'nama_cuti' => 'Cuti Tahunan',
            'tanggal_mulai' => $start, 'tanggal_selesai' => $end, 'alasan' => 'Keperluan keluarga', 'status' => $status,
        ]);
    }
}
