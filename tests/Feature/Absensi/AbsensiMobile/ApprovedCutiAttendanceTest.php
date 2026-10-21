<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Models\CutiRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\CutiScheduleService;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApprovedCutiAttendanceTest extends TestCase
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
        $this->pegawai = Pegawai::query()->create(['nama' => 'Pegawai Cuti', 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->user->id])->save();
        OfficeLocation::query()->create([
            'name' => 'RSARSY', 'latitude' => -6.2, 'longitude' => 106.8166667, 'radius_meters' => 100,
        ]);
        $path = "profile-photos/{$this->user->id}/wajah.jpg";
        Storage::disk('local')->put($path, 'profile photo');
        $this->user->forceFill(['profile_photo_path' => $path, 'profile_photo_updated_at' => now()])->save();
        $this->mock(FaceVerifier::class)->shouldNotReceive('compare');
    }

    public function test_only_final_approval_changes_the_schedule_to_cuti(): void
    {
        $cuti = $this->cuti(['status' => 'pending', 'current_level' => 1]);
        $approvers = User::factory()->count(2)->create();
        foreach ($approvers as $index => $approver) {
            $approver->assignRole('pegawai');
            $cuti->approvals()->create([
                'user_id' => $approver->id, 'approver_name' => $approver->name,
                'level' => $index + 1, 'status' => $index === 0 ? 'pending' : 'waiting',
            ]);
        }

        $this->actingAs($approvers[0])->post("/absensi/mobile/cuti/{$cuti->id}/decision", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame('pending', $cuti->fresh()->status);
        $this->assertSchedule(false);

        $this->actingAs($approvers[1])->post("/absensi/mobile/cuti/{$cuti->id}/decision", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $cuti->fresh()->status);
        $this->assertSchedule(true);
        $this->assertSame($this->shift->id, $this->pegawai->fresh()->shift_id);
        $this->assertDatabaseCount('presensis', 0);
    }

    #[DataProvider('cutiPeriods')]
    public function test_schedule_uses_only_approved_cuti_within_its_inclusive_dates(string $status, string $start, string $end, bool $onLeave): void
    {
        $this->cuti(['status' => $status, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
        $this->assertSchedule($onLeave);

        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/home')->assertOk()
            ->assertJsonPath('data.schedule.shift_name', $onLeave ? 'CUTI' : 'Shift Pagi')
            ->assertJsonPath('data.schedule.start_time', $onLeave ? null : '07:00')
            ->assertJsonPath('data.attendance.is_on_leave', $onLeave)
            ->assertJsonPath('data.attendance.status', $onLeave ? 'on_leave' : 'ready_to_clock_in');
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.shift.name', $onLeave ? 'CUTI' : 'Shift Pagi')
            ->assertJsonPath('data.shift.end_time', $onLeave ? null : '15:00')
            ->assertJsonPath('data.status', $onLeave ? 'on_leave' : 'ready_to_clock_in');
    }

    public static function cutiPeriods(): array
    {
        return [
            'pending' => ['pending', '2026-10-20', '2026-10-22', false],
            'rejected' => ['rejected', '2026-10-20', '2026-10-22', false],
            'cancelled' => ['cancelled', '2026-10-20', '2026-10-22', false],
            'future' => ['approved', '2026-10-22', '2026-10-24', false],
            'finished' => ['approved', '2026-10-18', '2026-10-20', false],
            'first day' => ['approved', '2026-10-21', '2026-10-23', true],
            'middle day' => ['approved', '2026-10-20', '2026-10-22', true],
            'last day' => ['approved', '2026-10-19', '2026-10-21', true],
            'single day' => ['approved', '2026-10-21', '2026-10-21', true],
        ];
    }

    public function test_another_employees_cuti_does_not_change_the_schedule(): void
    {
        $other = Pegawai::query()->create(['nama' => 'Pegawai Lain']);
        $this->cuti(['pegawai_id' => $other->id]);
        $this->assertSchedule(false);
    }

    #[DataProvider('attendanceActions')]
    public function test_cuti_blocks_pages_and_direct_submissions_without_changing_attendance(string $action, bool $api): void
    {
        $this->cuti();
        $attendance = $action === 'clock-out' ? $this->attendance() : null;
        if ($api) {
            Sanctum::actingAs($this->user, ['mobile']);
            $url = "/api/mobile/v1/attendance/{$action}";
            $this->getJson($url)->assertUnprocessable()
                ->assertJsonPath('errors.attendance.0', CutiScheduleService::BLOCKED_MESSAGE);
            $this->post($url, $this->payload($action), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonPath('errors.attendance.0', CutiScheduleService::BLOCKED_MESSAGE);
        } else {
            $this->actingAs($this->user)->get("/absensi/mobile/{$action}")
                ->assertRedirect('/absensi/mobile')->assertSessionHas('error', CutiScheduleService::BLOCKED_MESSAGE);
            $this->post("/absensi/mobile/{$action}", $this->payload($action))
                ->assertSessionHasErrors(['attendance' => CutiScheduleService::BLOCKED_MESSAGE]);
        }
        $this->assertDatabaseCount('presensis', $attendance ? 1 : 0);
        $this->assertNull($attendance?->fresh()->clock_out_at);
        $this->assertSame([], Storage::disk('local')->allFiles('presensi-photos'));
    }

    public static function attendanceActions(): array
    {
        return [
            'web clock-in' => ['clock-in', false], 'web clock-out' => ['clock-out', false],
            'api clock-in' => ['clock-in', true], 'api clock-out' => ['clock-out', true],
        ];
    }

    public function test_cuti_takes_priority_over_a_day_off_or_completed_attendance(): void
    {
        $this->cuti();
        $this->attendance()->update(['is_day_off' => true, 'clock_out_at' => now()]);
        $this->assertSchedule(true);
    }

    public function test_cuti_blocks_clock_out_from_an_overnight_shift(): void
    {
        $this->shift->update(['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $attendance = $this->attendance()->fill([
            'tanggal_presensi' => '2026-10-20', 'clock_in_at' => '2026-10-20 22:00:00',
        ]);
        $attendance->save();
        $this->cuti();
        $this->assertSchedule(true);
        $this->actingAs($this->user)->post('/absensi/mobile/clock-out', $this->payload('clock-out'))
            ->assertSessionHasErrors(['attendance' => CutiScheduleService::BLOCKED_MESSAGE]);
        $this->assertNull($attendance->fresh()->clock_out_at);

        // Also block a previous shift whose attendance date was covered by approved leave.
        CutiRequest::query()->update(['tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-20']);
        $this->post('/absensi/mobile/clock-out', $this->payload('clock-out'))
            ->assertSessionHasErrors(['attendance' => CutiScheduleService::BLOCKED_MESSAGE]);
    }

    public function test_normal_schedule_returns_after_cuti_even_with_unfinished_overnight_attendance(): void
    {
        $nightShift = Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->attendance()->update([
            'shift_id' => $nightShift->id, 'tanggal_presensi' => '2026-10-20', 'clock_in_at' => '2026-10-20 22:00:00',
        ]);
        $this->cuti(['tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-20']);
        $this->assertSchedule(false);
        $this->get('/absensi/mobile/clock-in')->assertOk();

        Sanctum::actingAs($this->user, ['mobile']);
        $this->getJson('/api/mobile/v1/home')->assertOk()
            ->assertJsonPath('data.schedule.shift_name', 'Shift Pagi')
            ->assertJsonPath('data.attendance.status', 'ready_to_clock_in');
        $this->getJson('/api/mobile/v1/attendance')->assertOk()
            ->assertJsonPath('data.status', 'ready_to_clock_in');
    }

    #[DataProvider('webAttendanceActions')]
    public function test_cuti_approved_during_face_verification_still_blocks_saving_and_removes_the_photo(string $action): void
    {
        $attendance = $action === 'clock-out' ? $this->attendance() : null;
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturnUsing(function () {
            $this->cuti();

            return new FaceVerificationResult(true, 98, 'test-verifier');
        });
        $this->actingAs($this->user)->post("/absensi/mobile/{$action}", $this->payload($action))
            ->assertSessionHasErrors(['attendance' => CutiScheduleService::BLOCKED_MESSAGE]);
        $this->assertDatabaseCount('presensis', $attendance ? 1 : 0);
        $this->assertNull($attendance?->fresh()->clock_out_at);
        $this->assertSame([], Storage::disk('local')->allFiles('presensi-photos'));
    }

    public static function webAttendanceActions(): array
    {
        return [['clock-in'], ['clock-out']];
    }

    private function assertSchedule(bool $onLeave): void
    {
        $this->actingAs($this->user)->get('/absensi/mobile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('todaySchedule.shift_name', $onLeave ? 'CUTI' : 'Shift Pagi')
                ->where('todaySchedule.start_time', $onLeave ? null : '07:00')
                ->where('todaySchedule.end_time', $onLeave ? null : '15:00')
                ->where('todayAttendance.is_on_leave', $onLeave));
        $this->get('/absensi/mobile/presensi')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('today.shift_name', $onLeave ? 'CUTI' : 'Shift Pagi')
                ->where('today.start_time', $onLeave ? null : '07:00')
                ->where('attendance.status', $onLeave ? 'on_leave' : 'ready_to_clock_in'));
    }

    private function cuti(array $overrides = []): CutiRequest
    {
        return CutiRequest::query()->create(array_replace([
            'user_id' => $this->user->id, 'pegawai_id' => $this->pegawai->id,
            'nama_pegawai' => $this->pegawai->nama, 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-10-21', 'tanggal_selesai' => '2026-10-23',
            'alasan' => 'Keperluan keluarga', 'status' => 'approved',
        ], $overrides));
    }

    private function attendance(): Presensi
    {
        return Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => now()->toDateString(), 'clock_in_at' => now()->startOfDay()->addHours(7),
        ]);
    }

    private function payload(string $action): array
    {
        $token = str_repeat('a', 64);
        $this->withSession(["attendance.location_challenges.{$action}" => [
            'token_hash' => hash('sha256', $token), 'user_id' => $this->user->id, 'issued_at' => now()->getTimestampMs(),
        ]]);

        return [
            'latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8,
            'location_token' => $token, 'outside_radius_confirmed' => false,
            'location_samples' => [[
                'latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8, 'captured_at' => now()->getTimestampMs(),
            ]],
            'photo' => UploadedFile::fake()->image('attendance.jpg', 800, 800)->size(120),
        ];
    }
}
