<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pegawai $pegawai;

    private Shift $shift;

    private OfficeLocation $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $this->shift = Shift::query()->create([
            'nama' => 'Shift Pagi IGD',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $this->pegawai = Pegawai::query()->create([
            'shift_id' => $this->shift->id,
            'nama' => 'Perawat Presensi',
        ]);
        $this->pegawai->forceFill(['user_id' => $this->user->id])->save();
        $this->office = OfficeLocation::query()->create([
            'name' => 'Gedung Utama RSARSY',
            'address' => 'Jl. Rumah Sakit No. 1',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);
    }

    public function test_attendance_service_is_ready_for_clock_in(): void
    {
        $this->travelTo('2026-09-16 06:45:00');

        $this->actingAs($this->user)
            ->get('/absensi/mobile/presensi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Attendance')
                ->where('today.date', '2026-09-16')
                ->where('today.shift_name', 'Shift Pagi IGD')
                ->where('today.start_time', '07:00')
                ->where('today.end_time', '15:00')
                ->where('today.location_name', 'Gedung Utama RSARSY')
                ->where('attendance.status', 'ready_to_clock_in')
                ->where('attendance.clock_in', null)
                ->where('attendance.clock_out', null));
    }

    public function test_attendance_service_displays_complete_clock_details(): void
    {
        $this->travelTo('2026-09-16 16:00:00');
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-16',
            'clock_in_at' => '2026-09-16 07:05:00',
            'clock_out_at' => '2026-09-16 15:02:00',
            'clock_in_location_id' => $this->office->id,
            'clock_out_location_id' => $this->office->id,
            'clock_in_latitude' => -6.2000000,
            'clock_in_longitude' => 106.8166667,
            'clock_out_latitude' => -6.2000000,
            'clock_out_longitude' => 106.8166667,
            'clock_in_address' => 'Jl. Rumah Sakit No. 1',
            'clock_out_address' => 'Jl. Rumah Sakit No. 1',
            'face_verified_at' => '2026-09-16 07:05:01',
            'face_similarity' => 96.42,
            'clock_out_face_verified_at' => '2026-09-16 15:02:01',
            'clock_out_face_similarity' => 97.25,
            'notes' => 'Presensi dari area IGD.',
        ]);

        $this->actingAs($this->user)
            ->get('/absensi/mobile/presensi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Attendance')
                ->where('attendance.status', 'complete')
                ->where('attendance.duration', '7 jam 57 menit')
                ->where('attendance.notes', 'Presensi dari area IGD.')
                ->where('attendance.clock_in.time', '07:05')
                ->where('attendance.clock_in.location_name', 'Gedung Utama RSARSY')
                ->where('attendance.clock_in.outside_radius', false)
                ->where('attendance.clock_in.face_verified', true)
                ->where('attendance.clock_in.face_similarity', 96.42)
                ->where('attendance.clock_in.schedule_status', 'Terlambat 5 menit')
                ->where('attendance.clock_out.time', '15:02')
                ->where('attendance.clock_out.face_verified', true)
                ->where('attendance.clock_out.face_similarity', 97.25)
                ->where('attendance.clock_out.schedule_status', 'Sesuai jadwal'));
    }

    public function test_ongoing_overnight_shift_is_shown_as_today_attendance(): void
    {
        $this->shift->update([
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ]);
        $this->travelTo('2026-09-16 05:30:00');
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 21:55:00',
            'clock_in_location_id' => $this->office->id,
        ]);

        $this->actingAs($this->user)
            ->get('/absensi/mobile/presensi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('today.date', '2026-09-15')
                ->where('today.shift_name', 'Shift Malam')
                ->where('attendance.status', 'ready_to_clock_out')
                ->where('attendance.clock_in.time', '21:55')
                ->where('attendance.clock_out', null));
    }
}
