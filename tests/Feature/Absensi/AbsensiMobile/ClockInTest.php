<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Exceptions\FaceVerificationException;
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
                ->andReturn(new FaceVerificationResult(true, 96.42, 'test-verifier'));
        });

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
                ->where('office.radius_meters', 100)
                ->where('locationChallenge.server_time', fn (int $timestamp): bool => abs(now()->getTimestampMs() - $timestamp) < 1000)
                ->where('locationChallenge.min_samples', 1)
                ->where('locationChallenge.collection_seconds', 0)
                ->where('locationChallenge.max_speed_meters_per_second', 80));
    }

    public function test_employee_can_clock_in_inside_office_radius(): void
    {
        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertRedirect('/absensi/mobile/clock-in/berhasil')
            ->assertSessionHas('success', 'Clock-in berhasil. Selamat bekerja!');

        $presensi = Presensi::query()->sole();

        $this->assertSame($this->pegawai->id, $presensi->pegawai_id);
        $this->assertSame($this->office->id, $presensi->clock_in_location_id);
        $this->assertNotNull($presensi->clock_in_at);
        $this->assertSame(-6.2, $presensi->clock_in_latitude);
        $this->assertSame(106.8166667, $presensi->clock_in_longitude);
        $this->assertSame(8.0, $presensi->clock_in_accuracy);
        $this->assertSame(1, $presensi->clock_in_location_sample_count);
        $this->assertNotNull($presensi->clock_in_location_verified_at);
        $this->assertSame('Jl. Rumah Sakit No. 1', $presensi->clock_in_address);
        $this->assertNull($presensi->notes);
        $this->assertNotNull($presensi->face_verified_at);
        $this->assertSame(96.42, $presensi->face_similarity);
        $this->assertSame('test-verifier', $presensi->face_verification_provider);
        Storage::disk('local')->assertExists($presensi->clock_in_photo_path);

        $this->get('/absensi/mobile/clock-in/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockInSuccess')
                ->where('attendance.clock_in_time', $presensi->clock_in_at->format('H:i'))
                ->where('attendance.late_minutes', fn (int $minutes) => $minutes >= 0)
                ->where('attendance.shift_name', 'Shift Pagi')
                ->where('attendance.start_time', '07:00')
                ->where('attendance.end_time', '15:00')
                ->where('attendance.location_name', 'Gedung Utama RSARSY')
                ->where('attendance.outside_radius', false)
                ->where('attendance.face_verified', true)
                ->where('attendance.face_similarity', 96.42));
    }

    public function test_clock_in_success_reports_late_minutes_from_the_shift_start_time(): void
    {
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->pegawai->shift_id,
            'tanggal_presensi' => '2026-09-16',
            'clock_in_at' => '2026-09-16 07:10:00',
        ]);

        $this->travelTo('2026-09-16 12:00:00');

        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-in/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockInSuccess')
                ->where('attendance.clock_in_time', '07:10')
                ->where('attendance.late_minutes', 10));
    }

    public function test_clock_in_success_reports_zero_late_minutes_when_on_time(): void
    {
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->pegawai->shift_id,
            'tanggal_presensi' => '2026-09-16',
            'clock_in_at' => '2026-09-16 06:55:00',
        ]);

        $this->travelTo('2026-09-16 12:00:00');

        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-in/berhasil')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/ClockInSuccess')
                ->where('attendance.late_minutes', 0));
    }

    public function test_clock_in_is_rejected_when_face_does_not_match_profile(): void
    {
        $this->mock(FaceVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compare')
                ->once()
                ->andReturn(new FaceVerificationResult(false, null, 'test-verifier'));
        });

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors([
                'photo' => 'Wajah tidak cocok dengan foto profil. Pastikan Anda menggunakan akun sendiri lalu coba verifikasi kembali.',
            ]);

        $this->assertDatabaseCount('presensis', 0);
        $this->assertSame([], Storage::disk('local')->allFiles("presensi-photos/{$this->pegawai->id}"));
    }

    public function test_clock_in_rejects_a_request_without_server_issued_location_proof(): void
    {
        $payload = $this->payload();
        unset($payload['location_token']);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertSessionHasErrors('location_token');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_rejects_an_untrusted_network_when_network_lock_is_enabled(): void
    {
        config()->set('attendance.location.require_trusted_network', true);
        config()->set('attendance.location.trusted_networks', ['10.0.0.0/8']);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors('location_proof');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_rejects_an_impossible_gps_jump(): void
    {
        $payload = $this->payload();
        $payload['location_samples'][] = array_merge($payload['location_samples'][0], [
            'latitude' => -7.257472,
            'longitude' => 112.75209,
            'captured_at' => $payload['location_samples'][0]['captured_at'] + 1_500,
        ]);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertSessionHasErrors('location_proof');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_still_rejects_stale_or_future_gps_samples(): void
    {
        foreach ([-60_000, 60_000] as $offset) {
            $payload = $this->payload();
            $payload['location_samples'][0]['captured_at'] += $offset;

            $this->actingAs($this->user)
                ->post('/absensi/mobile/clock-in', $payload)
                ->assertSessionHasErrors([
                    'location_proof' => 'Sampel GPS tidak segar. Ambil lokasi kembali sebelum melakukan presensi.',
                ]);
        }

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_can_retry_face_verification_with_fresh_gps_for_the_new_challenge(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->mock(FaceVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compare')->twice()->andReturn(
                new FaceVerificationResult(false, null, 'test-verifier'),
                new FaceVerificationResult(true, 96.42, 'test-verifier'),
            );
        });

        $payload = $this->payload();
        $firstChallenge = $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-in')
            ->assertOk()->viewData('page')['props']['locationChallenge'];
        $payload['location_token'] = $firstChallenge['token'];
        $this->post('/absensi/mobile/clock-in', $payload)->assertSessionHasErrors('photo');

        $this->travel(1)->minutes();
        $nextChallenge = $this->get('/absensi/mobile/clock-in')
            ->assertOk()->viewData('page')['props']['locationChallenge'];
        $this->assertNotSame($firstChallenge['token'], $nextChallenge['token']);
        $payload['location_token'] = $nextChallenge['token'];
        $payload['location_samples'][0]['captured_at'] = $nextChallenge['server_time'];

        $this->post('/absensi/mobile/clock-in', $payload)
            ->assertRedirect('/absensi/mobile/clock-in/berhasil');
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_clock_in_rejects_a_mock_location_even_when_its_accuracy_is_poor(): void
    {
        $payload = $this->payload();
        $payload['location_samples'][] = array_merge($payload['location_samples'][0], [
            'accuracy' => 5000,
            'is_mock' => '1',
        ]);

        $this->mock(FaceVerifier::class, fn (MockInterface $mock) => $mock->shouldNotReceive('compare'));
        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertSessionHasErrors([
                'location_proof' => 'Fake GPS terdeteksi. Nonaktifkan aplikasi lokasi palsu lalu ambil lokasi kembali.',
            ]);

        $this->assertDatabaseCount('presensis', 0);
        $this->assertSame([], Storage::disk('local')->allFiles("presensi-photos/{$this->pegawai->id}"));
    }

    public function test_android_clock_in_requires_a_mock_check_for_every_sample(): void
    {
        $payload = $this->payload(['location_source' => 'android_native']);
        $payload['location_samples'][] = array_merge($payload['location_samples'][0], [
            'captured_at' => $payload['location_samples'][0]['captured_at'] + 1_500,
        ]);
        foreach ($payload['location_samples'] as &$sample) {
            $sample['is_mock'] = false;
        }
        unset($sample, $payload['location_samples'][1]['is_mock']);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertSessionHasErrors('location_samples.1.is_mock');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_android_clock_in_accepts_explicit_non_mock_samples(): void
    {
        $payload = $this->payload(['location_source' => 'android_native']);
        foreach ($payload['location_samples'] as &$sample) {
            $sample['is_mock'] = '0';
        }
        unset($sample);

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertRedirect('/absensi/mobile/clock-in/berhasil');

        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_clock_in_uses_the_verified_sample_median_instead_of_top_level_coordinates(): void
    {
        $payload = $this->payload();
        $payload['latitude'] = -6.19;
        $payload['longitude'] = 106.80;

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $payload)
            ->assertRedirect('/absensi/mobile/clock-in/berhasil');

        $presensi = Presensi::query()->sole();
        $this->assertSame(-6.2, $presensi->clock_in_latitude);
        $this->assertSame(106.8166667, $presensi->clock_in_longitude);
    }

    public function test_clock_in_is_rejected_when_face_cannot_be_detected(): void
    {
        $this->mock(FaceVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compare')
                ->once()
                ->andThrow(new FaceVerificationException(
                    FaceVerificationException::FACE_NOT_FOUND,
                    'Wajah tidak ditemukan.',
                ));
        });

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_fails_closed_when_face_service_is_unavailable(): void
    {
        $this->mock(FaceVerifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compare')
                ->once()
                ->andThrow(new FaceVerificationException(
                    FaceVerificationException::SERVICE_UNAVAILABLE,
                    'Provider unavailable.',
                ));
        });

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors('attendance');

        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_clock_in_requires_a_private_profile_photo(): void
    {
        Storage::disk('local')->delete($this->user->profile_photo_path);
        $this->user->forceFill([
            'profile_photo_path' => null,
            'profile_photo_updated_at' => null,
        ])->save();

        $this->actingAs($this->user)
            ->get('/absensi/mobile/clock-in')
            ->assertRedirect('/absensi/mobile/akun')
            ->assertSessionHas('error');

        $this->actingAs($this->user)
            ->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('presensis', 0);
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
            ->assertRedirect('/absensi/mobile/clock-in/berhasil')
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
            ->assertRedirect('/absensi/mobile/clock-in/berhasil');

        $this->assertDatabaseCount('presensis', 1);
        $this->assertNotNull($presensi->fresh()->clock_in_at);
    }

    public function test_clock_in_reloads_a_dated_shift_created_during_face_verification(): void
    {
        $this->travelTo('2026-10-26 06:00:00');
        $target = Shift::query()->create(['nama' => 'Shift Siang', 'jam_masuk' => '14:00', 'jam_keluar' => '22:00']);
        $this->mock(FaceVerifier::class, function (MockInterface $mock) use ($target): void {
            $mock->shouldReceive('compare')->once()->andReturnUsing(function () use ($target) {
                Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'tanggal_presensi' => now()->toDateString(), 'shift_id' => $target->id]);

                return new FaceVerificationResult(true, 96.42, 'test-verifier');
            });
        });
        $this->actingAs($this->user)->post('/absensi/mobile/clock-in', $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect('/absensi/mobile/clock-in/berhasil');
        $record = Presensi::query()->sole();
        $this->assertSame($target->id, $record->shift_id);
        $this->assertNotNull($record->clock_in_at);
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
            'photo' => UploadedFile::fake()->image('clock-in.jpg', 800, 800)->size(120),
        ], $overrides);

        $token = str_repeat('a', 64);
        $issuedAt = now()->getTimestampMs();
        $this->withSession([
            'attendance.location_challenges.clock-in' => [
                'token_hash' => hash('sha256', $token),
                'user_id' => $this->user->id,
                'issued_at' => $issuedAt,
            ],
        ]);

        $payload['location_token'] = $token;
        $payload['location_samples'] = [0];
        $payload['location_samples'] = array_map(fn (int $offset): array => [
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'accuracy' => $payload['accuracy'],
            'captured_at' => $issuedAt + $offset,
        ], $payload['location_samples']);

        return $payload;
    }
}
