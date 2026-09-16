<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_home_displays_the_assigned_shift_hours_and_configured_work_location(): void
    {
        $user = User::factory()->create();
        $user->assignRole('pegawai');
        $shift = Shift::query()->create([
            'nama' => 'Shift Pagi IGD',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $pegawai = Pegawai::query()->create([
            'shift_id' => $shift->id,
            'nama' => 'Pegawai IGD',
        ]);
        $pegawai->forceFill(['user_id' => $user->id])->save();
        OfficeLocation::query()->create([
            'name' => 'Gedung IGD',
            'address' => 'Area IGD',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);

        $this->actingAs($user)
            ->get('/absensi/mobile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Home')
                ->where('todaySchedule.shift_name', 'Shift Pagi IGD')
                ->where('todaySchedule.start_time', '07:00')
                ->where('todaySchedule.end_time', '15:00')
                ->where('todaySchedule.location_name', 'Gedung IGD'));
    }

    public function test_home_returns_empty_schedule_values_when_assignment_is_not_configured(): void
    {
        $user = User::factory()->create();
        $user->assignRole('pegawai');

        $this->actingAs($user)
            ->get('/absensi/mobile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Home')
                ->where('todaySchedule.shift_name', null)
                ->where('todaySchedule.start_time', null)
                ->where('todaySchedule.end_time', null)
                ->where('todaySchedule.location_name', null));
    }
}
