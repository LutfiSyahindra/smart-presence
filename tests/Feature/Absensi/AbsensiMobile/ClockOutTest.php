<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ClockOutTest extends TestCase
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
        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $this->shift = Shift::query()->create([
            'nama' => 'Shift Pagi',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $this->pegawai = Pegawai::query()->create([
            'shift_id' => $this->shift->id,
            'nama' => 'Perawat Satu',
        ]);
        $this->pegawai->forceFill(['user_id' => $this->user->id])->save();

        $profilePhotoPath = "profile-photos/{$this->user->id}/wajah.jpg";
        Storage::disk('local')->put(
            $profilePhotoPath,
            UploadedFile::fake()->image('wajah.jpg', 800, 800)->getContent(),
        );
        $this->user->forceFill([
            'profile_photo_path' => $profilePhotoPath,
            'profile_photo_updated_at' => now(),
        ])->save();

        $this->mock(FaceVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compare')
                ->zeroOrMoreTimes()
                ->andReturn(new FaceVerificationResult(true, 97.25, 'test-verifier'));
        });

        $this->office = OfficeLocation::query()->create([
            'name' => 'Gedung Utama RSARSY',
            'address' => 'Jl. Rumah Sakit No. 1',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);
    }

    public function test_employee_can_open_clock_out_after_clocking_in(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);

        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-out')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockOut')
                ->where('schedule.shift_name', 'Shift Pagi')
                ->where('schedule.start_time', '07:00')
                ->where('schedule.end_time', '15:00')
                ->where('office.id', $this->office->id));
    }

    public function test_employee_can_clock_out_and_early_departure_is_reported(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $presensi = $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $this->payload())
            ->assertRedirect('/absensi/mobile/clock-out/berhasil')
            ->assertSessionHas('success', 'Clock-out berhasil. Terima kasih atas kerja keras Anda!');

        $presensi->refresh();
        $this->assertSame('14:30', $presensi->clock_out_at?->format('H:i'));
        $this->assertSame(30, $presensi->earlyDepartureMinutes());
        $this->assertSame($this->office->id, $presensi->clock_out_location_id);
        $this->assertSame('Jl. Rumah Sakit No. 1', $presensi->clock_out_address);
        $this->assertSame(8.0, $presensi->clock_out_accuracy);
        $this->assertSame(3, $presensi->clock_out_location_sample_count);
        $this->assertNotNull($presensi->clock_out_location_verified_at);
        $this->assertNotNull($presensi->clock_out_face_verified_at);
        $this->assertSame(97.25, $presensi->clock_out_face_similarity);
        $this->assertSame('test-verifier', $presensi->clock_out_face_verification_provider);
        Storage::disk('local')->assertExists($presensi->clock_out_photo_path);

        $this->get('/absensi/mobile/clock-out/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockOutSuccess')
                ->where('attendance.clock_out_time', '14:30')
                ->where('attendance.early_departure_minutes', 30)
                ->where('attendance.outside_radius', false)
                ->where('attendance.face_verified', true)
                ->where('attendance.face_similarity', 97.25));
    }

    public function test_clock_out_at_or_after_shift_end_is_not_early(): void
    {
        $this->travelTo('2026-09-16 15:05:00');
        $presensi = $this->attendance([
            'clock_in_at' => '2026-09-16 07:00:00',
            'clock_out_at' => '2026-09-16 15:05:00',
        ]);

        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-out/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('attendance.early_departure_minutes', 0));

        $this->assertSame(0, $presensi->earlyDepartureMinutes());
    }

    public function test_clock_out_requires_clock_in_and_cannot_be_submitted_twice(): void
    {
        $this->travelTo('2026-09-16 15:00:00');

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $this->payload())
            ->assertSessionHasErrors('attendance');

        $this->attendance([
            'clock_in_at' => '2026-09-16 07:00:00',
            'clock_out_at' => '2026-09-16 15:00:00',
        ]);

        $this->post('/absensi/mobile/clock-out', $this->payload())
            ->assertSessionHasErrors('attendance');

        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_clock_out_outside_radius_requires_a_note_and_preserves_existing_notes(): void
    {
        $this->travelTo('2026-09-16 15:00:00');
        $presensi = $this->attendance([
            'clock_in_at' => '2026-09-16 07:00:00',
            'notes' => 'Catatan clock-in sebelumnya.',
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $this->payload([
                'latitude' => -6.19,
                'outside_radius_confirmed' => true,
                'outside_radius_note' => '',
            ]))
            ->assertSessionHasErrors('outside_radius_note');

        $this->post('/absensi/mobile/clock-out', $this->payload([
            'latitude' => -6.19,
            'outside_radius_confirmed' => true,
            'outside_radius_note' => 'Mengantar pasien ke gedung sebelah.',
        ]))->assertRedirect('/absensi/mobile/clock-out/berhasil');

        $presensi->refresh();
        $this->assertStringContainsString('Catatan clock-in sebelumnya.', (string) $presensi->notes);
        $this->assertStringContainsString('Catatan clock-out di luar radius: Mengantar pasien ke gedung sebelah.', (string) $presensi->notes);
        $this->assertStringContainsString('Di luar radius Gedung Utama RSARSY', (string) $presensi->clock_out_address);
    }

    public function test_overnight_shift_clocks_out_against_the_next_day_end_time(): void
    {
        $this->shift->update([
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ]);
        $this->travelTo('2026-09-16 05:30:00');
        $presensi = $this->attendance([
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 21:55:00',
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $this->payload())
            ->assertRedirect('/absensi/mobile/clock-out/berhasil');

        $presensi->refresh();
        $this->assertSame('2026-09-16 05:30', $presensi->clock_out_at?->format('Y-m-d H:i'));
        $this->assertSame(30, $presensi->earlyDepartureMinutes());

        $this->get('/absensi/mobile/clock-out/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('attendance.clock_out_time', '05:30')
                ->where('attendance.early_departure_minutes', 30));

        $this->get('/absensi/mobile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaySchedule.shift_name', 'Shift Malam')
                ->where('todayAttendance.clocked_in_at', '21:55')
                ->where('todayAttendance.clocked_out_at', '05:30'));
    }

    public function test_clock_out_rejects_fake_gps_without_saving_photo_or_clock_out(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $presensi = $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);
        $payload = $this->payload(['location_source' => 'android_native']);
        foreach ($payload['location_samples'] as &$sample) {
            $sample['is_mock'] = false;
        }
        unset($sample);
        $payload['location_samples'][] = array_merge($payload['location_samples'][2], [
            'accuracy' => 5000,
            'is_mock' => true,
        ]);

        $this->mock(FaceVerifier::class, fn (MockInterface $mock) => $mock->shouldNotReceive('compare'));
        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $payload)
            ->assertSessionHasErrors([
                'location_proof' => 'Fake GPS terdeteksi. Nonaktifkan aplikasi lokasi palsu lalu ambil lokasi kembali.',
            ]);

        $this->assertNull($presensi->fresh()->clock_out_at);
        $this->assertSame([], Storage::disk('local')->allFiles("presensi-photos/{$this->pegawai->id}"));
    }

    public function test_android_clock_out_requires_native_mock_checks(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $presensi = $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $this->payload(['location_source' => 'android_native']))
            ->assertSessionHasErrors('location_samples.0.is_mock');

        $this->assertNull($presensi->fresh()->clock_out_at);
    }

    public function test_android_clock_out_accepts_non_mock_samples(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $presensi = $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);
        $payload = $this->payload(['location_source' => 'android_native']);
        foreach ($payload['location_samples'] as &$sample) {
            $sample['is_mock'] = '0';
        }
        unset($sample);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-out', $payload)
            ->assertRedirect('/absensi/mobile/clock-out/berhasil');

        $this->assertNotNull($presensi->fresh()->clock_out_at);
    }

    /** @param array<string, mixed> $overrides */
    private function attendance(array $overrides = []): Presensi
    {
        return Presensi::query()->create(array_merge([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-16',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'accuracy' => 8,
            'outside_radius_confirmed' => false,
            'outside_radius_note' => '',
            'photo' => UploadedFile::fake()->image('clock-out.jpg', 800, 800)->size(120),
        ], $overrides);

        $token = str_repeat('b', 64);
        $issuedAt = now()->getTimestampMs() - 4_000;
        $this->withSession([
            'attendance.location_challenges.clock-out' => [
                'token_hash' => hash('sha256', $token),
                'user_id' => $this->user->id,
                'issued_at' => $issuedAt,
            ],
        ]);

        $payload['location_token'] = $token;
        $payload['location_samples'] = [0, 1_500, 3_000];
        $payload['location_samples'] = array_map(fn (int $offset): array => [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'accuracy' => $payload['accuracy'],
            'captured_at' => $issuedAt + $offset,
        ], $payload['location_samples']);

        return $payload;
    }
}
