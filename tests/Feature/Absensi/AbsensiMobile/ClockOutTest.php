<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Jobs\ProcessAttendanceSubmission;
use App\Models\AttendanceSubmission;
use App\Models\CutiRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\ConfigurationService;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClockOutTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\ConfiguresPresensiApproval;

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
                ->where('office.id', $this->office->id)
                ->where('locationChallenge.server_time', fn (int $timestamp): bool => abs(now()->getTimestampMs() - $timestamp) < 1000)
                ->where('locationChallenge.min_samples', 1)
                ->where('locationChallenge.collection_seconds', 0));
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
        $this->assertSame(1, $presensi->clock_out_location_sample_count);
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

    public function test_realtime_refresh_keeps_the_active_location_challenge_valid(): void
    {
        $this->travelTo('2026-09-16 14:30:00');
        $this->attendance(['clock_in_at' => '2026-09-16 07:00:00']);
        $initial = $this->actingAs($this->user)->get('/absensi/mobile/clock-out')->assertOk();
        $challenge = session('attendance.location_challenges.clock-out');
        $this->assertNotNull($challenge);
        $this->get('/absensi/mobile/clock-out', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $initial->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'absensi/absensi-mobile/ClockOut',
            'X-Inertia-Partial-Data' => 'schedule,office,profilePhotoUrl',
        ])->assertOk()->assertJsonMissingPath('props.locationChallenge');
        $this->assertSame($challenge, session('attendance.location_challenges.clock-out'));
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

    #[DataProvider('clockOutWithoutClockInModes')]
    public function test_clock_out_without_clock_in_follows_configuration(bool $enabled, bool $dated, bool $api, bool $queued): void
    {
        $this->travelTo('2026-09-16 15:00:00');
        app(ConfigurationService::class)->update(['clock_out_without_clock_in' => $enabled], $this->user->id);
        config(['attendance.queue_enabled' => $queued]);
        if ($queued) {
            Queue::fake();
        }
        if ($dated) {
            $this->pegawai->update(['shift_id' => null]);
            $this->attendance(['notes' => 'Jadwal khusus hari ini.']);
        }
        $this->actingAs($this->user)->get('/absensi/mobile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('todayAttendance.can_clock_out', $enabled));
        $this->get('/absensi/mobile/presensi')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attendance.can_clock_out', $enabled));
        Sanctum::actingAs($this->user);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()->assertJsonPath('data.can_clock_out', $enabled);
        $url = ($api ? '/api/mobile/v1/attendance/' : '/absensi/mobile/').'clock-out';
        $form = $api ? $this->getJson($url) : $this->get($url);
        $payload = $this->payload();
        if (! $enabled) {
            $api ? $form->assertUnprocessable() : $form->assertRedirect('/absensi/mobile');
            $response = $api ? $this->postJson($url, $payload) : $this->post($url, $payload);
            $api ? $response->assertUnprocessable()->assertJsonValidationErrors('attendance') : $response->assertSessionHasErrors('attendance');
            $this->assertDatabaseCount('presensis', $dated ? 1 : 0);
            $this->assertDatabaseCount('attendance_submissions', 0);
            $this->assertSame(0, Presensi::query()->whereNotNull('clock_out_at')->count());

            return;
        }
        $form->assertOk();
        if ($api) {
            $payload['location_token'] = $form->json('data.location_challenge.token');
        }
        $this->assertDatabaseCount('presensis', $dated ? 1 : 0);
        $response = $api ? $this->postJson($url, $payload) : $this->post($url, $payload);
        $api ? $response->assertStatus($queued ? 202 : 200) : $response->assertSessionHasNoErrors()->assertRedirect();
        if ($queued) {
            $submission = AttendanceSubmission::query()->sole();
            $this->assertSame('2026-09-16', $submission->attendance_date->toDateString());
            $this->assertSame($this->shift->id, $submission->shift_id);
            $this->travelTo('2026-09-16 16:00:00');
            app()->call([new ProcessAttendanceSubmission($submission->id), 'handle']);
            $this->assertSame('succeeded', $submission->fresh()->status);
        }
        $attendance = Presensi::query()->sole();
        $this->assertNull($attendance->clock_in_at);
        $this->assertNull($attendance->clock_in_photo_path);
        $this->assertSame('2026-09-16 15:00:00', $attendance->clock_out_at?->toDateTimeString());
        $this->assertSame($this->shift->id, $attendance->shift_id);
        $this->assertNotNull($attendance->clock_out_face_verified_at);
        $this->assertNotNull($attendance->clock_out_location_verified_at);
        if ($dated) {
            $this->assertSame('Jadwal khusus hari ini.', $attendance->notes);
        }
        $this->get('/absensi/mobile/clock-out/berhasil')->assertOk();
        $this->getJson('/api/mobile/v1/attendance')->assertOk()->assertJsonPath('data.can_clock_out', false)
            ->assertJsonPath('data.clock_in', null)->assertJsonPath('data.clock_out.time', '15:00');
        $duplicate = $this->payload();
        $duplicate['location_token'] = str_repeat('c', 64);
        $response = $api ? $this->postJson($url, $duplicate) : $this->post($url, $duplicate);
        $api ? $response->assertUnprocessable()->assertJsonValidationErrors('attendance') : $response->assertSessionHasErrors('attendance');
        $this->get('/absensi/mobile/clock-in')->assertRedirect('/absensi/mobile');
        $this->post('/absensi/mobile/clock-in', $this->payload(action: 'clock-in'))->assertSessionHasErrors('attendance');
        $this->assertNull($attendance->fresh()->clock_in_at);
        $this->assertDatabaseCount('presensis', 1);
    }

    public static function clockOutWithoutClockInModes(): iterable
    {
        foreach ([false, true] as $enabled) {
            foreach ([false, true] as $dated) {
                foreach ([false, true] as $api) {
                    foreach ([false, true] as $queued) {
                        yield implode(' ', [$enabled ? 'allowed' : 'blocked', $dated ? 'dated' : 'default', $api ? 'API' : 'web', $queued ? 'queued' : 'direct']) => [$enabled, $dated, $api, $queued];
                    }
                }
            }
        }
    }

    #[DataProvider('processingModes')]
    public function test_clock_out_without_clock_in_keeps_location_holiday_and_cuti_checks(bool $queued): void
    {
        $this->travelTo('2026-09-16 15:00:00');
        app(ConfigurationService::class)->update(['clock_out_without_clock_in' => true], $this->user->id);
        config(['attendance.queue_enabled' => $queued]);
        Queue::fake();
        $this->actingAs($this->user)->post('/absensi/mobile/clock-out', $this->payload([
            'latitude' => -6.19, 'outside_radius_confirmed' => true,
        ]))->assertSessionHasErrors('outside_radius_note');
        $payload = $this->payload();
        $payload['location_samples'][0]['is_mock'] = true;
        $this->post('/absensi/mobile/clock-out', $payload)->assertSessionHasErrors('location_proof');
        $this->shift->update(['is_day_off' => true]);
        $this->post('/absensi/mobile/clock-out', $this->payload())->assertSessionHasErrors('attendance');
        $this->shift->update(['is_day_off' => false]);
        CutiRequest::query()->create([
            'user_id' => $this->user->id, 'pegawai_id' => $this->pegawai->id,
            'nama_pegawai' => $this->pegawai->nama, 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-09-16', 'tanggal_selesai' => '2026-09-16',
            'alasan' => 'Keperluan keluarga', 'status' => 'approved',
        ]);
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('todayAttendance.can_clock_out', false));
        $this->get('/absensi/mobile/clock-out')->assertRedirect('/absensi/mobile');
        $this->post('/absensi/mobile/clock-out', $this->payload())->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('presensis', 0);
        $this->assertDatabaseCount('attendance_submissions', 0);
    }

    #[DataProvider('processingModes')]
    public function test_clock_out_without_clock_in_still_requires_a_matching_face(bool $queued): void
    {
        $this->travelTo('2026-09-16 15:00:00');
        app(ConfigurationService::class)->update(['clock_out_without_clock_in' => true], $this->user->id);
        config(['attendance.queue_enabled' => $queued]);
        Queue::fake();
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(false, 20, 'test'));
        $response = $this->actingAs($this->user)->post('/absensi/mobile/clock-out', $this->payload());
        if ($queued) {
            $response->assertSessionHasNoErrors();
            $submission = AttendanceSubmission::query()->sole();
            app()->call([new ProcessAttendanceSubmission($submission->id), 'handle']);
            $this->assertSame('failed', $submission->fresh()->status);
            $this->assertSame('photo', $submission->fresh()->error_field);
        } else {
            $response->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_queued_clock_out_rechecks_configuration_before_saving_without_clock_in(): void
    {
        $this->travelTo('2026-09-16 15:00:00');
        $configuration = app(ConfigurationService::class);
        $configuration->update(['clock_out_without_clock_in' => true], $this->user->id);
        config(['attendance.queue_enabled' => true]);
        Queue::fake();
        $this->actingAs($this->user)->post('/absensi/mobile/clock-out', $this->payload())->assertSessionHasNoErrors();
        $submission = AttendanceSubmission::query()->sole();
        $configuration->update(['clock_out_without_clock_in' => false], $this->user->id);
        app()->call([new ProcessAttendanceSubmission($submission->id), 'handle']);
        $this->assertSame('failed', $submission->fresh()->status);
        $this->assertDatabaseCount('presensis', 0);
    }

    public static function processingModes(): array
    {
        return ['direct' => [false], 'queued' => [true]];
    }

    public function test_clock_out_outside_radius_requires_a_note_and_preserves_existing_notes(): void
    {
        $this->configurePresensiApproval($this->pegawai);
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
                ->where('todayAttendance.clocked_in_at', null)
                ->where('todayAttendance.clocked_out_at', null));
    }

    #[DataProvider('overnightClockOutChannels')]
    public function test_unfinished_night_shift_can_clock_out_after_its_end_despite_todays_schedule(bool $api, bool $sameShift): void
    {
        $this->travelTo('2026-09-16 08:00:00');
        $this->shift->update(['jam_masuk' => '14:00', 'jam_keluar' => '21:00']);
        $nightShift = Shift::query()->create([
            'nama' => 'Shift Malam', 'jam_masuk' => '21:00', 'jam_keluar' => '07:00',
        ]);
        $night = $this->attendance([
            'shift_id' => $nightShift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 21:00:00',
        ]);
        $today = $this->attendance(['shift_id' => $sameShift ? $nightShift->id : $this->shift->id]);

        $this->actingAs($this->user)->get('/absensi/mobile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaySchedule.shift_name', 'Shift Malam')
                ->where('todayAttendance.clocked_in_at', '21:00')
                ->where('todayAttendance.clocked_out_at', null));
        $this->get('/absensi/mobile/presensi')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('today.date', '2026-09-15')
                ->where('attendance.status', 'ready_to_clock_out'));
        $this->get('/absensi/mobile/presensi?action=create')->assertRedirectToRoute('absensi.mobile.clock-out.create');
        $this->get('/absensi/mobile/clock-out')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.date', '2026-09-15')
                ->where('schedule.shift_name', 'Shift Malam')
                ->where('schedule.end_time', '07:00'));

        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/home')->assertOk()
            ->assertJsonPath('data.schedule.shift_name', 'Shift Malam')
            ->assertJsonPath('data.attendance.status', 'ready_to_clock_out');
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.id', $night->id)
            ->assertJsonPath('data.date', '2026-09-15')
            ->assertJsonPath('data.status', 'ready_to_clock_out');
        $apiAction = $this->getJson('/api/mobile/v1/attendance/clock-out')->assertOk()
            ->assertJsonPath('data.schedule.date', '2026-09-15')
            ->assertJsonPath('data.schedule.shift_name', 'Shift Malam');

        if ($api) {
            $payload = $this->payload();
            $payload['location_token'] = $apiAction->json('data.location_challenge.token');
            $this->postJson('/api/mobile/v1/attendance/clock-out', $payload)->assertOk()
                ->assertJsonPath('data.date', '2026-09-15')
                ->assertJsonPath('data.shift_name', 'Shift Malam')
                ->assertJsonPath('data.time', '08:00');
        } else {
            $this->post('/absensi/mobile/clock-out', $this->payload())
                ->assertSessionHasNoErrors()->assertRedirect('/absensi/mobile/clock-out/berhasil');
            $this->get('/absensi/mobile/clock-out/berhasil')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('attendance.date', '2026-09-15')
                    ->where('attendance.shift_name', 'Shift Malam')
                    ->where('attendance.clock_out_time', '08:00')
                    ->where('attendance.early_departure_minutes', 0));
        }

        $this->assertSame('2026-09-16 08:00:00', $night->fresh()->clock_out_at?->toDateTimeString());
        $this->assertNull($today->fresh()->clock_in_at);
        $this->assertNull($today->fresh()->clock_out_at);
        $this->assertSame($sameShift ? $nightShift->id : $this->shift->id, $today->fresh()->shift_id);
        $this->assertDatabaseCount('presensis', 2);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.id', $today->id)
            ->assertJsonPath('data.status', 'ready_to_clock_in');
    }

    public static function overnightClockOutChannels(): array
    {
        return [
            'mobile web with next day shift' => [false, false],
            'mobile web with next night shift' => [false, true],
            'mobile API with next day shift' => [true, false],
            'mobile API with next night shift' => [true, true],
        ];
    }

    public function test_current_clock_in_takes_priority_over_an_unfinished_previous_night_shift(): void
    {
        $this->travelTo('2026-09-16 08:00:00');
        $nightShift = Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '21:00', 'jam_keluar' => '07:00']);
        $night = $this->attendance([
            'shift_id' => $nightShift->id, 'tanggal_presensi' => '2026-09-15', 'clock_in_at' => '2026-09-15 21:00:00',
        ]);
        $today = $this->attendance(['clock_in_at' => '2026-09-16 07:30:00']);

        $this->actingAs($this->user)->get('/absensi/mobile/clock-out')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.date', '2026-09-16')->where('schedule.shift_name', 'Shift Pagi'));
        $this->post('/absensi/mobile/clock-out', $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect('/absensi/mobile/clock-out/berhasil');

        $this->assertSame('2026-09-16 08:00:00', $today->fresh()->clock_out_at?->toDateTimeString());
        $this->assertNull($night->fresh()->clock_out_at);
    }

    #[DataProvider('nextShiftTransitions')]
    public function test_next_shift_opens_at_its_start_when_previous_clock_out_was_forgotten(bool $dated, bool $api, bool $queued): void
    {
        $this->travelTo('2026-09-16 13:59:59');
        config(['attendance.queue_enabled' => $queued]);
        if ($queued) {
            Queue::fake();
        }
        $nightShift = Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '21:00', 'jam_keluar' => '07:00']);
        $nextShift = Shift::query()->create(['nama' => 'Shift Siang', 'jam_masuk' => '14:00', 'jam_keluar' => '21:00']);
        $night = $this->attendance([
            'shift_id' => $nightShift->id, 'tanggal_presensi' => '2026-09-15', 'clock_in_at' => '2026-09-15 21:00:00',
        ]);
        if ($dated) {
            $this->attendance(['shift_id' => $nextShift->id]);
        } else {
            $this->pegawai->update(['shift_id' => $nextShift->id]);
        }

        $this->actingAs($this->user)->get('/absensi/mobile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaySchedule.shift_name', 'Shift Malam')->where('todayAttendance.clocked_in_at', '21:00'));
        $this->get('/absensi/mobile/clock-out')->assertOk();
        $this->get('/absensi/mobile/clock-in')->assertRedirect('/absensi/mobile');
        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.id', $night->id)->assertJsonPath('data.status', 'ready_to_clock_out');
        $this->getJson('/api/mobile/v1/attendance/clock-in')->assertUnprocessable();
        $this->getJson('/api/mobile/v1/attendance/clock-out')->assertOk();

        $this->travelTo('2026-09-16 14:00:00');
        $this->get('/absensi/mobile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaySchedule.shift_name', 'Shift Siang')->where('todayAttendance.clocked_in_at', null));
        $this->get('/absensi/mobile/presensi')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('today.date', '2026-09-16')->where('attendance.status', 'ready_to_clock_in'));
        $this->get('/absensi/mobile/presensi?action=create')->assertRedirectToRoute('absensi.mobile.clock-in.create');
        $this->get('/absensi/mobile/clock-in')->assertOk();
        $this->get('/absensi/mobile/clock-out')->assertRedirect('/absensi/mobile');
        $this->getJson('/api/mobile/v1/home')->assertOk()
            ->assertJsonPath('data.schedule.shift_name', 'Shift Siang')
            ->assertJsonPath('data.attendance.status', 'ready_to_clock_in');
        $this->getJson('/api/mobile/v1/attendance/clock-out')->assertUnprocessable();

        // A clock-out form opened before the boundary must not close the old shift afterward.
        if ($api) {
            $this->postJson('/api/mobile/v1/attendance/clock-out', $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('attendance');
        } else {
            $this->post('/absensi/mobile/clock-out', $this->payload())->assertSessionHasErrors('attendance');
        }
        $this->assertNull($night->fresh()->clock_out_at);

        $this->get('/absensi/mobile/presensi/log?month=2026-09')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('days.14.status', 'no_clock_out')->where('days.14.clock_out', null));
        $challenge = $this->getJson('/api/mobile/v1/attendance/clock-in')->assertOk()
            ->assertJsonPath('data.schedule.shift_name', 'Shift Siang');
        $payload = $this->payload(action: 'clock-in');
        if ($api) {
            $payload['location_token'] = $challenge->json('data.location_challenge.token');
            $this->postJson('/api/mobile/v1/attendance/clock-in', $payload)->assertStatus($queued ? 202 : 201);
        } else {
            $this->post('/absensi/mobile/clock-in', $payload)->assertSessionHasNoErrors()->assertRedirect();
        }
        if ($queued) {
            $submission = AttendanceSubmission::query()->sole();
            $this->assertSame('2026-09-16', $submission->attendance_date->toDateString());
            $this->assertSame($nextShift->id, $submission->shift_id);
            $this->travelTo('2026-09-16 14:05:00');
            app()->call([new ProcessAttendanceSubmission($submission->id), 'handle']);
            $this->assertSame('succeeded', $submission->fresh()->status);
        }

        $today = $this->pegawai->presensis()->whereDate('tanggal_presensi', '2026-09-16')->sole();
        $this->assertSame($nextShift->id, $today->shift_id);
        $this->assertSame('2026-09-16 14:00:00', $today->clock_in_at?->toDateTimeString());
        $this->assertNull($night->fresh()->clock_out_at);
        $this->assertDatabaseCount('presensis', 2);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.id', $today->id)->assertJsonPath('data.status', 'ready_to_clock_out');
    }

    public static function nextShiftTransitions(): array
    {
        return [
            'dated web' => [true, false, false], 'dated API' => [true, true, false],
            'default web' => [false, false, false], 'default API' => [false, true, false],
            'dated queued web' => [true, false, true], 'dated queued API' => [true, true, true],
            'default queued web' => [false, false, true], 'default queued API' => [false, true, true],
        ];
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
        $payload['location_samples'][] = array_merge($payload['location_samples'][0], [
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
    private function payload(array $overrides = [], string $action = 'clock-out'): array
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
        $issuedAt = now()->getTimestampMs();
        $this->withSession([
            "attendance.location_challenges.{$action}" => [
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
