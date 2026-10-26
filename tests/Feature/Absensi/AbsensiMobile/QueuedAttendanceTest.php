<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Exceptions\FaceVerificationException;
use App\Jobs\ProcessAttendanceSubmission;
use App\Models\AttendanceSubmission;
use App\Models\CutiRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\QueuedAttendanceService;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueuedAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pegawai $pegawai;

    private Shift $shift;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-26 06:58:00');
        config(['attendance.queue_enabled' => true, 'attendance.location.require_trusted_network' => false]);
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->user->assignRole('pegawai');
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Pegawai Queue', 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->user->id])->save();
        OfficeLocation::query()->create(['name' => 'RSARSY', 'latitude' => -6.2, 'longitude' => 106.8166667, 'radius_meters' => 100]);
        $profile = "profile-photos/{$this->user->id}/profile.jpg";
        Storage::disk('local')->put($profile, 'original profile');
        $this->user->forceFill(['profile_photo_path' => $profile])->save();
    }

    public function test_acceptance_does_not_verify_or_claim_success_and_status_can_be_reopened(): void
    {
        $this->mock(FaceVerifier::class)->shouldNotReceive('compare');
        $submission = $this->submit();
        $this->assertSame('pending', $submission->status);
        $this->assertDatabaseCount('presensis', 0);
        Queue::assertPushedOn('attendance', ProcessAttendanceSubmission::class);
        $this->assertNull(session('attendance.location_challenges.clock-in'));
        $this->get('/absensi/mobile/clock-in')->assertRedirect($submission->statusPayload()['status_url']);
        $this->get($submission->statusPayload()['status_url'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/AttendanceSubmission')->where('submission.status', 'pending')->where('submission.time', '06:58'));
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('attendanceSubmissions.0.id', $submission->id));
    }

    public function test_delayed_and_duplicate_jobs_preserve_the_original_time_and_shift_date(): void
    {
        $this->travelTo('2026-10-26 23:58:00');
        $submission = $this->submit();
        $this->travelTo('2026-10-27 00:10:00');
        $this->succeed($submission);
        $this->process($submission);
        $attendance = Presensi::query()->sole();
        $this->assertSame('2026-10-26', $attendance->tanggal_presensi->toDateString());
        $this->assertSame('2026-10-26 23:58:00', $attendance->clock_in_at->toDateTimeString());
        $this->assertSame('succeeded', $submission->fresh()->status);
        $this->assertNull($submission->fresh()->active_key);
    }

    public function test_a_retry_uses_the_profile_photo_snapshot_from_submission_time(): void
    {
        $submission = $this->submit();
        Storage::disk('local')->put($this->user->profile_photo_path, 'replacement profile');
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->with('original profile', \Mockery::type('string'))
            ->andReturn(new FaceVerificationResult(true, 96, 'test'));
        $this->process($submission);
        $this->assertSame('succeeded', $submission->fresh()->status);
    }

    public function test_clock_out_keeps_its_original_overnight_shift_and_received_time(): void
    {
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '07:00']);
        $attendance = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-25', 'clock_in_at' => '2026-10-25 22:00:00']);
        $submission = $this->submit('clock-out');
        $this->travelTo('2026-10-27 01:00:00');
        $this->succeed($submission);
        $this->assertSame('2026-10-26 06:58:00', $attendance->fresh()->clock_out_at->toDateTimeString());
        $this->assertSame(2, $attendance->fresh()->earlyDepartureMinutes());
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_network_failure_is_retried_without_changing_the_received_time(): void
    {
        $submission = $this->submit();
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()
            ->andThrow(new FaceVerificationException(FaceVerificationException::SERVICE_UNAVAILABLE, 'offline'));
        try {
            $this->process($submission);
            $this->fail('An unavailable service must release the job for retry.');
        } catch (FaceVerificationException $exception) {
            $this->assertSame(FaceVerificationException::SERVICE_UNAVAILABLE, $exception->reason);
        }
        $this->assertSame('pending', $submission->fresh()->status);
        $this->travel(10)->minutes();
        $this->succeed($submission);
        $this->assertSame('06:58', Presensi::query()->sole()->clock_in_at->format('H:i'));
        $this->assertSame(2, $submission->fresh()->attempts);
    }

    public function test_rejected_face_is_terminal_and_a_fresh_photo_can_be_submitted(): void
    {
        $submission = $this->submit();
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(false, 20, 'test'));
        $this->process($submission);
        $this->process($submission);
        $this->assertSame('failed', $submission->fresh()->status);
        $this->assertSame('photo', $submission->fresh()->error_field);
        $this->assertDatabaseCount('presensis', 0);
        $replacement = $this->submit('clock-in', 'b');
        $this->assertNotSame($submission->id, $replacement->id);
        $this->assertSame('pending', $replacement->status);
        $this->assertCount(1, Storage::disk('local')->files("attendance-profile-snapshots/{$this->user->id}"));
    }

    public function test_spoof_rejection_is_not_retried(): void
    {
        $submission = $this->submit();
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()
            ->andThrow(new FaceVerificationException(FaceVerificationException::SPOOF_DETECTED, 'spoof'));
        $this->process($submission);
        $this->assertSame('failed', $submission->fresh()->status);
        $this->assertSame(1, $submission->fresh()->attempts);
        $this->assertStringContainsString('layar', $submission->fresh()->error_message);
    }

    public function test_repeated_post_with_consumed_proof_returns_the_existing_submission(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->user)->post('/absensi/mobile/clock-in', $payload)->assertSessionHasNoErrors();
        $submission = AttendanceSubmission::query()->sole();
        $this->post('/absensi/mobile/clock-in', $payload)->assertRedirect($submission->statusPayload()['status_url'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_submissions', 1);
        Queue::assertPushed(ProcessAttendanceSubmission::class, 1);
        $this->assertCount(1, Storage::disk('local')->files("presensi-photos/{$this->pegawai->id}/clock-in"));
    }

    public function test_a_new_proof_while_pending_does_not_create_another_job(): void
    {
        $original = $this->submit();
        $repeat = $this->submit('clock-in', 'b');
        $this->assertSame($original->id, $repeat->id);
        Queue::assertPushed(ProcessAttendanceSubmission::class, 1);
    }

    public function test_status_is_owner_only_on_web_and_api_and_does_not_expose_internal_paths(): void
    {
        $submission = $this->submit();
        $url = $submission->statusPayload()['status_url'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.photo_path')->assertJsonMissingPath('data.attendance_attributes');
        $other = User::factory()->create();
        $other->assignRole('pegawai');
        $this->actingAs($other)->get($url)->assertNotFound();
        Sanctum::actingAs($other);
        $this->getJson('/api/mobile/v1/attendance-submissions/'.$submission->id)->assertNotFound();
    }

    public function test_api_returns_accepted_and_can_resume_after_reopening(): void
    {
        Sanctum::actingAs($this->user);
        $context = $this->getJson('/api/mobile/v1/attendance/clock-in')->assertOk()->json('data.location_challenge');
        $payload = $this->payload();
        $payload['location_token'] = $context['token'];
        $response = $this->postJson('/api/mobile/v1/attendance/clock-in', $payload)->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $id = $response->json('data.id');
        $this->getJson('/api/mobile/v1/attendance-submissions/latest?action=clock-in')->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/mobile/v1/attendance-submissions/'.$id)->assertOk()->assertJsonPath('data.time', '06:58');
    }

    public function test_invalid_gps_never_creates_a_submission_or_job(): void
    {
        $payload = $this->payload();
        $payload['location_samples'][0]['captured_at'] = now()->subHours(2)->getTimestampMs();
        $this->actingAs($this->user)->post('/absensi/mobile/clock-in', $payload)->assertSessionHasErrors('location_proof');
        $this->assertDatabaseCount('attendance_submissions', 0);
        Queue::assertNothingPushed();
    }

    public function test_recovery_requeues_an_abandoned_processing_request(): void
    {
        $submission = $this->submit();
        $submission->forceFill(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now(), 'processing_token' => (string) Str::uuid()])->save();
        $this->travel(3)->minutes();
        $this->artisan('attendance:recover')->assertSuccessful();
        Queue::assertPushed(ProcessAttendanceSubmission::class, 2);
        $this->succeed($submission);
        $this->assertSame('succeeded', $submission->fresh()->status);
    }

    public function test_a_dispatch_outage_keeps_the_receipt_and_photos_for_scheduler_recovery(): void
    {
        $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue offline'));
        try {
            $submission = $this->submit();
        } finally {
            Bus::swap($dispatcher);
        }
        $this->assertSame('pending', $submission->status);
        $this->assertNull($submission->last_dispatched_at);
        Storage::disk('local')->assertExists($submission->photo_path);
        Storage::disk('local')->assertExists($submission->profile_snapshot_path);
        $this->travel(3)->minutes();
        $this->artisan('attendance:recover')->assertSuccessful();
        Queue::assertPushed(ProcessAttendanceSubmission::class, 1);
    }

    public function test_recovery_leaves_fresh_requests_alone(): void
    {
        $this->submit();
        $this->artisan('attendance:recover')->assertSuccessful();
        Queue::assertPushed(ProcessAttendanceSubmission::class, 1);
    }

    public function test_only_one_worker_can_verify_the_same_request_at_a_time(): void
    {
        $submission = $this->submit();
        $this->mock(FaceVerifier::class)->shouldNotReceive('compare');
        $lock = Cache::lock('attendance-submission:'.$submission->id, 75);
        $this->assertTrue($lock->get());
        try {
            $this->process($submission);
            $this->assertSame('pending', $submission->fresh()->status);
            $this->assertSame(0, $submission->fresh()->attempts);
        } finally {
            $lock->release();
        }
    }

    public function test_exhausted_retries_produce_a_visible_failure(): void
    {
        $submission = $this->submit();
        $submission->forceFill(['attempts' => 3])->save();
        $this->mock(FaceVerifier::class)->shouldNotReceive('compare');
        $this->process($submission);
        $this->assertSame('failed', $submission->fresh()->status);
        $this->assertNull($submission->fresh()->active_key);
    }

    public function test_worker_failure_callback_allows_a_new_attempt(): void
    {
        $submission = $this->submit();
        (new ProcessAttendanceSubmission($submission->id))->failed(new \RuntimeException('worker failure'));
        $this->assertSame('failed', $submission->fresh()->status);
    }

    public function test_an_admin_attendance_is_not_overwritten_during_face_verification(): void
    {
        $submission = $this->submit();
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturnUsing(function () {
            Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
                'tanggal_presensi' => '2026-10-26', 'clock_in_at' => '2026-10-26 06:30:00']);

            return new FaceVerificationResult(true, 96, 'test');
        });
        $this->process($submission);
        $this->assertSame('failed', $submission->fresh()->status);
        $this->assertSame('06:30', Presensi::query()->sole()->clock_in_at->format('H:i'));
    }

    public function test_leave_on_the_next_day_does_not_reject_yesterdays_valid_submission(): void
    {
        $submission = $this->submit();
        CutiRequest::query()->create(['user_id' => $this->user->id, 'pegawai_id' => $this->pegawai->id,
            'nama_pegawai' => $this->pegawai->nama, 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-10-27', 'tanggal_selesai' => '2026-10-27', 'alasan' => 'Cuti', 'status' => 'approved']);
        $this->travelTo('2026-10-27 01:00:00');
        $this->succeed($submission);
        $this->assertSame('succeeded', $submission->fresh()->status);
    }

    public function test_three_hundred_database_queue_requests_complete_once_even_with_duplicate_deliveries(): void
    {
        $first = $this->submit();
        config(['queue.default' => 'database']);
        Queue::swap($this->realQueue);
        $service = app(QueuedAttendanceService::class);
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->times(300)->andReturn(new FaceVerificationResult(true, 96, 'test'));
        $service->dispatch($first);
        $service->dispatch($first);
        for ($index = 1; $index < 300; $index++) {
            $user = User::factory()->create();
            $pegawai = Pegawai::query()->create(['nama' => 'Pegawai Uji '.$index, 'shift_id' => $this->shift->id]);
            $pegawai->forceFill(['user_id' => $user->id])->save();
            $submission = $first->replicate();
            $submission->forceFill([
                'id' => (string) Str::uuid(), 'pegawai_id' => $pegawai->id, 'user_id' => $user->id,
                'request_key' => hash('sha256', 'burst-'.$index), 'active_key' => "{$pegawai->id}:clock-in",
                'attendance_attributes' => array_replace($first->attendance_attributes, ['updated_by' => $user->id]),
            ])->save();
            $service->dispatch($submission);
        }
        $this->assertDatabaseCount('jobs', 301);
        $this->travel(5)->minutes();
        $this->artisan('queue:work', ['--queue' => 'attendance', '--stop-when-empty' => true, '--sleep' => 0, '--max-jobs' => 400, '--tries' => 3])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('presensis', 300);
        $this->assertSame(300, AttendanceSubmission::query()->where('status', 'succeeded')->count());
        $this->assertSame(0, AttendanceSubmission::query()->whereNotNull('active_key')->count());
        $this->assertSame(0, Presensi::query()->where('clock_in_at', '!=', '2026-10-26 06:58:00')->count());
    }

    private function submit(string $action = 'clock-in', string $tokenCharacter = 'a'): AttendanceSubmission
    {
        $response = $this->actingAs($this->user)->post('/absensi/mobile/'.$action, $this->payload($action, $tokenCharacter))
            ->assertSessionHasNoErrors();
        $id = basename($response->headers->get('Location'));

        return AttendanceSubmission::query()->findOrFail($id);
    }

    private function succeed(AttendanceSubmission $submission): void
    {
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(true, 96, 'test'));
        $this->process($submission);
    }

    private function process(AttendanceSubmission $submission): void
    {
        app()->call([new ProcessAttendanceSubmission($submission->id), 'handle']);
    }

    private function payload(string $action = 'clock-in', string $tokenCharacter = 'a'): array
    {
        $token = str_repeat($tokenCharacter, 64);
        $this->withSession(["attendance.location_challenges.{$action}" => [
            'token_hash' => hash('sha256', $token), 'user_id' => $this->user->id, 'issued_at' => now()->getTimestampMs(),
        ]]);

        return ['latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8,
            'location_token' => $token, 'outside_radius_confirmed' => false,
            'location_samples' => [['latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8, 'captured_at' => now()->getTimestampMs()]],
            'photo' => UploadedFile::fake()->image('attendance.jpg', 800, 800)->size(120)];
    }
}
