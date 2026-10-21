<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Models\HariLibur;
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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShiftHolidayAttendanceTest extends TestCase
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
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $this->shift = Shift::query()->create(['nama' => 'Shift Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Pegawai Uji', 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->user->id])->save();
        OfficeLocation::query()->create(['name' => 'Kantor', 'latitude' => -6.2, 'longitude' => 106.8166667, 'radius_meters' => 100]);
        $path = "profile-photos/{$this->user->id}/wajah.jpg";
        Storage::disk('local')->put($path, 'profile photo');
        $this->user->forceFill(['profile_photo_path' => $path, 'profile_photo_updated_at' => now()])->save();
    }

    #[DataProvider('holidayPolicies')]
    public function test_web_and_api_schedules_follow_only_the_selected_holiday_categories(string $date, array $policy, ?string $type, bool $off): void
    {
        $this->travelTo($date.' 12:00:00');
        $this->shift->update($policy);
        if ($type) {
            HariLibur::query()->create(['tanggal' => $date, 'nama' => 'Libur Uji', 'jenis' => $type]);
        }
        $this->actingAs($this->user)->get('/absensi/mobile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('todayAttendance.is_day_off', $off)
            ->where('todaySchedule.start_time', $off ? null : '07:00'));
        $this->get('/absensi/mobile/presensi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('attendance.is_day_off', $off)
            ->where('attendance.status', $off ? 'day_off' : 'ready_to_clock_in'));
        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/home')->assertOk()
            ->assertJsonPath('data.attendance.is_day_off', $off)
            ->assertJsonPath('data.attendance.status', $off ? 'day_off' : 'ready_to_clock_in');
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.is_day_off', $off)
            ->assertJsonPath('data.shift.start_time', $off ? null : '07:00');
        $this->assertDatabaseCount('presensis', 0);
    }

    public static function holidayPolicies(): array
    {
        return [
            'Saturday off' => ['2026-10-24', ['libur_sabtu' => true], null, true],
            'Sunday still working' => ['2026-10-25', ['libur_sabtu' => true], null, false],
            'Sunday off' => ['2026-10-25', ['libur_minggu' => true], null, true],
            'Saturday still working' => ['2026-10-24', ['libur_minggu' => true], null, false],
            'weekday' => ['2026-10-21', ['libur_sabtu' => true, 'libur_minggu' => true], null, false],
            'national off' => ['2026-10-21', ['libur_nasional' => true], HariLibur::NASIONAL, true],
            'collective off' => ['2026-10-21', ['libur_cuti_bersama' => true], HariLibur::CUTI_BERSAMA, true],
            'national does not include collective' => ['2026-10-21', ['libur_nasional' => true], HariLibur::CUTI_BERSAMA, false],
            'collective does not include national' => ['2026-10-21', ['libur_cuti_bersama' => true], HariLibur::NASIONAL, false],
            'unconfigured shift' => ['2026-10-21', [], HariLibur::NASIONAL, false],
            'empty calendar' => ['2026-10-21', ['libur_nasional' => true, 'libur_cuti_bersama' => true], null, false],
        ];
    }

    #[DataProvider('attendanceActions')]
    public function test_holidays_block_action_pages_and_direct_submissions(string $action, bool $api): void
    {
        $this->shift->update(['libur_nasional' => true]);
        HariLibur::query()->create(['tanggal' => now()->toDateString(), 'nama' => 'Libur Uji', 'jenis' => HariLibur::NASIONAL]);
        $this->mock(FaceVerifier::class)->shouldNotReceive('compare');
        if ($api) {
            Sanctum::actingAs($this->user, ['mobile']);
            $url = "/api/mobile/v1/attendance/{$action}";
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('attendance');
            $this->post($url, $this->payload(), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attendance');
        } else {
            $this->actingAs($this->user)->get("/absensi/mobile/{$action}")->assertRedirect('/absensi/mobile');
            $this->post("/absensi/mobile/{$action}", $this->payload())->assertSessionHasErrors('attendance');
        }
        $this->assertDatabaseCount('presensis', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('presensi-photos'));
    }

    public static function attendanceActions(): array
    {
        return [['clock-in', false], ['clock-out', false], ['clock-in', true], ['clock-out', true]];
    }

    public function test_an_overnight_shift_can_finish_on_a_holiday(): void
    {
        $this->travelTo('2026-10-24 06:00:00');
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00', 'libur_sabtu' => true]);
        $attendance = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 22:00:00',
        ]);
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(true, 98, 'test'));
        $this->actingAs($this->user)->get('/absensi/mobile/presensi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('attendance.is_day_off', false)->where('attendance.status', 'ready_to_clock_out'));
        $this->get('/absensi/mobile/clock-out')->assertOk();
        $token = str_repeat('a', 64);
        $this->withSession(['attendance.location_challenges.clock-out' => [
            'token_hash' => hash('sha256', $token), 'user_id' => $this->user->id, 'issued_at' => now()->getTimestampMs(),
        ]]);
        $this->post('/absensi/mobile/clock-out', array_replace($this->payload(), ['location_token' => $token]))->assertSessionHasNoErrors();
        $this->assertNotNull($attendance->fresh()->clock_out_at);
    }

    public function test_another_shifts_policy_and_other_calendar_dates_do_not_apply(): void
    {
        Shift::query()->create(['nama' => 'Shift Lain', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00', 'libur_nasional' => true]);
        $this->shift->update(['libur_nasional' => true]);
        HariLibur::query()->create(['tanggal' => '2027-10-21', 'nama' => 'Libur Tahun Depan', 'jenis' => HariLibur::NASIONAL]);
        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()->assertJsonPath('data.is_day_off', false);
        $this->shift->update(['libur_nasional' => false]);
        HariLibur::query()->create(['tanggal' => '2026-10-21', 'nama' => 'Libur Uji', 'jenis' => HariLibur::NASIONAL]);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()->assertJsonPath('data.is_day_off', false);
    }

    private function payload(): array
    {
        return [
            'latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8,
            'location_token' => str_repeat('a', 64), 'outside_radius_confirmed' => false,
            'location_samples' => [[
                'latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8, 'captured_at' => now()->getTimestampMs(),
            ]],
            'photo' => UploadedFile::fake()->image('attendance.jpg', 800, 800)->size(120),
        ];
    }
}
