<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClockInTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pegawai $pegawai;

    private OfficeLocation $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $shift = Shift::query()->create([
            'nama' => 'Shift Pagi',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $this->pegawai = Pegawai::query()->create([
            'shift_id' => $shift->id,
            'nama' => 'Perawat Satu',
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

    public function test_employee_can_open_clock_in_location_step(): void
    {
        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-in')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockIn')
                ->where('schedule.shift_name', 'Shift Pagi')
                ->where('schedule.start_time', '07:00')
                ->where('schedule.end_time', '15:00')
                ->where('office.id', $this->office->id)
                ->where('office.name', 'Gedung Utama RSARSY')
                ->where('office.latitude', -6.2)
                ->where('office.longitude', 106.8166667)
                ->where('office.radius_meters', 100));
    }

    public function test_employee_can_clock_in_inside_office_radius(): void
    {
        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertRedirect('/absensi/mobile')
            ->assertSessionHas('success', 'Clock-in berhasil. Selamat bekerja!');

        $presensi = Presensi::query()->sole();

        $this->assertSame($this->pegawai->id, $presensi->pegawai_id);
        $this->assertSame($this->office->id, $presensi->clock_in_location_id);
        $this->assertNotNull($presensi->clock_in_at);
        $this->assertSame(-6.2, $presensi->clock_in_latitude);
        $this->assertSame(106.8166667, $presensi->clock_in_longitude);
        $this->assertSame('Jl. Rumah Sakit No. 1', $presensi->clock_in_address);
        $this->assertNull($presensi->notes);
        Storage::disk('local')->assertExists($presensi->clock_in_photo_path);
    }

    public function test_outside_radius_requires_confirmation_and_a_note(): void
    {
        $outsidePayload = $this->payload([
            'latitude' => -6.19,
            'outside_radius_confirmed' => false,
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $outsidePayload)
            ->assertSessionHasErrors('outside_radius_confirmed');

        $this->assertDatabaseCount('presensis', 0);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload([
                'latitude' => -6.19,
                'outside_radius_confirmed' => true,
                'outside_radius_note' => '',
            ]))
            ->assertSessionHasErrors('outside_radius_note');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_employee_can_clock_in_outside_radius_with_mandatory_note(): void
    {
        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload([
                'latitude' => -6.19,
                'outside_radius_confirmed' => true,
                'outside_radius_note' => 'Mendampingi pasien di gedung sebelah.',
            ]))
            ->assertRedirect('/absensi/mobile')
            ->assertSessionHas('success', 'Clock-in berhasil dikirim dengan catatan lokasi di luar radius.');

        $presensi = Presensi::query()->sole();
        $this->assertSame('Mendampingi pasien di gedung sebelah.', $presensi->notes);
        $this->assertStringContainsString('Di luar radius Gedung Utama RSARSY', $presensi->clock_in_address);
    }

    public function test_clock_in_cannot_be_submitted_twice_on_the_same_day(): void
    {
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->pegawai->shift_id,
            'tanggal_presensi' => now()->toDateString(),
            'clock_in_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors('attendance');

        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_clock_in_completes_an_existing_empty_attendance_record(): void
    {
        $presensi = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->pegawai->shift_id,
            'tanggal_presensi' => now()->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertRedirect('/absensi/mobile');

        $this->assertDatabaseCount('presensis', 1);
        $this->assertNotNull($presensi->fresh()->clock_in_at);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'accuracy' => 8,
            'outside_radius_confirmed' => false,
            'outside_radius_note' => '',
            'photo' => UploadedFile::fake()->image('clock-in.jpg', 800, 800)->size(120),
        ], $overrides);
    }
}
