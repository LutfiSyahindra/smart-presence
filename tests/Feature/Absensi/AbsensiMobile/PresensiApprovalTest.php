<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Jobs\ProcessAttendanceSubmission;
use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\AttendanceSubmission;
use App\Models\Jabatan;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\PresensiRequest;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Repositories\Absensi\AdminWeb\Presensi\PresensiRepository;
use App\Services\Absensi\AbsensiMobile\PresensiApprovalService;
use App\Services\Absensi\AbsensiMobile\QueuedAttendanceService;
use App\Services\Notifications\PresensiNotificationService;
use App\Support\AccessPermission;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PresensiApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $first;

    private User $second;

    private Pegawai $pegawai;

    private ApprovalLine $line;

    private const WEB = '/absensi/admin-web/notifikasi';

    private const MOBILE = '/absensi/mobile/presensi/persetujuan';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-30 07:00:00');
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        Bus::fake([SendMobilePushNotification::class, SendWebPushNotification::class, ProcessAttendanceSubmission::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create()->assignRole('pegawai');
        $this->first = User::factory()->create();
        $this->first->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        $this->second = User::factory()->create()->assignRole('pegawai');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'P', 'nama' => 'Perawat', 'aktif' => true]);
        $shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Pegawai Radius', 'unit_kerja_id' => $unit->id,
            'jabatan_id' => $position->id, 'shift_id' => $shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->owner->id])->save();
        OfficeLocation::query()->create(['name' => 'RSARSY', 'latitude' => -6.2, 'longitude' => 106.8166667, 'radius_meters' => 100]);
        $photo = "profile-photos/{$this->owner->id}/face.jpg";
        Storage::disk('local')->put($photo, 'profile');
        $this->owner->forceFill(['profile_photo_path' => $photo])->save();
        $this->line = ApprovalLine::query()->create(['type' => ApprovalLine::TYPE_PRESENSI]);
        $this->line->jabatans()->attach($position->id, ['type' => ApprovalLine::TYPE_PRESENSI]);
        foreach ([$this->first, $this->second] as $index => $approver) {
            $this->line->steps()->create(['user_id' => $approver->id, 'level' => $index + 1]);
        }
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->zeroOrMoreTimes()->andReturn(new FaceVerificationResult(true, 96, 'test'));
    }

    private function payload(string $action = 'clock-in', bool $outside = true): array
    {
        $token = str_repeat('a', 64);
        $this->withSession(["attendance.location_challenges.{$action}" => [
            'token_hash' => hash('sha256', $token), 'user_id' => $this->owner->id, 'issued_at' => now()->getTimestampMs(),
        ]]);
        $latitude = $outside ? -6.21 : -6.2;

        return ['latitude' => $latitude, 'longitude' => 106.8166667, 'accuracy' => 8, 'location_token' => $token,
            'location_samples' => [['latitude' => $latitude, 'longitude' => 106.8166667, 'accuracy' => 8, 'captured_at' => now()->getTimestampMs()]],
            'outside_radius_confirmed' => $outside, 'outside_radius_note' => 'Mendampingi pasien di lokasi lain.',
            'photo' => UploadedFile::fake()->image('face.jpg', 800, 800)->size(120)];
    }

    private function submit(string $action = 'clock-in'): PresensiRequest
    {
        $this->actingAs($this->owner)->post('/absensi/mobile/'.$action, $this->payload($action))->assertSessionHasNoErrors();

        return PresensiRequest::query()->latest('id')->firstOrFail();
    }

    private function web(PresensiRequest $item): string
    {
        return self::WEB.'/pengajuan/presensi/'.$item->id;
    }

    public function test_web_then_mobile_decision_advances_in_order_and_notifies_each_stage(): void
    {
        $item = $this->submit();
        $this->assertSame('pending', Presensi::query()->sole()->clock_in_approval_status);
        $this->assertSame(['pending', 'waiting'], $item->approvals->pluck('status')->all());
        $this->assertSame(1, $this->first->notifications()->where('data->event', 'submitted')->count());
        $this->assertSame(0, $this->second->notifications()->count());
        $this->actingAs($this->first)->get($this->web($item))->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/PresensiDetail')->where('presensiRequest.can_decide', true));
        $this->get(self::WEB.'/butuh-approval?request_type=presensi')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.request_type', 'presensi')->where('requests.data.0.action', 'clock-in')
            ->where('requestTypeCounts.presensi', 1)->where('adminNotificationCounts.pending_approval_count', 1));
        $this->getJson(self::WEB.'/feed')->assertJsonPath('pending_approval_count', 1);
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(2, $item->fresh()->current_level);
        $this->assertSame('pending', Presensi::query()->sole()->clock_in_approval_status);
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(1, $this->second->notifications()->where('data->event', 'approval_required')->count());
        $this->actingAs($this->second)->get('/absensi/mobile/notifikasi?tab=approvals')->assertInertia(fn (Assert $page) => $page
            ->where('presensiPendingApprovalCount', 1)->where('pendingApprovalCount', 1));
        $this->get(self::MOBILE.'/'.$item->id)->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/PresensiApprovalDetail')->where('presensiRequest.can_decide', true));
        $this->post(self::MOBILE.'/'.$item->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame('approved', Presensi::query()->sole()->clock_in_approval_status);
        $this->assertSame('07:00', Presensi::query()->sole()->clock_in_at->format('H:i'));
        $notice = $this->owner->notifications()->where('data->event', 'approved')->sole();
        $this->actingAs($this->owner)->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertRedirect(self::MOBILE.'/'.$item->id);
        Bus::assertDispatched(SendMobilePushNotification::class, fn ($job) => $job->notificationId === $notice->id);
        Bus::assertDispatched(SendWebPushNotification::class);
        $this->actingAs($this->first)->get(self::WEB.'/disetujui')->assertInertia(fn (Assert $page) => $page->where('overview.approved', 1));
    }

    public function test_clock_out_has_an_independent_request_and_rejection_keeps_the_evidence(): void
    {
        $in = $this->submit();
        $this->travelTo('2026-10-30 15:00:00');
        $out = $this->submit('clock-out');
        $this->assertNotSame($in->id, $out->id);
        $this->actingAs($this->first)->post($this->web($out).'/decision', ['decision' => 'rejected', 'note' => '  '])->assertSessionHasErrors('note');
        $this->post($this->web($out).'/decision', ['decision' => 'rejected', 'note' => 'Lokasi tidak sesuai tugas'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'skipped'], $out->fresh()->approvals->pluck('status')->all());
        $record = Presensi::query()->sole();
        $this->assertSame('pending', $record->clock_in_approval_status);
        $this->assertSame('rejected', $record->clock_out_approval_status);
        $this->assertSame('15:00', $record->clock_out_at->format('H:i'));
        $this->assertTrue(Storage::disk('local')->exists($record->clock_out_photo_path));
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'rejected')->count());
        $this->post($this->web($out).'/decision', ['decision' => 'approved'])->assertForbidden();
    }

    public function test_owner_unrelated_user_later_approver_and_unassigned_super_admin_cannot_decide(): void
    {
        $item = $this->submit();
        $stranger = User::factory()->create()->assignRole('pegawai');
        $admin = User::factory()->create()->assignRole('super-admin');
        foreach ([$this->owner, $this->second, $stranger, $admin] as $actor) {
            $this->actingAs($actor)->post(self::MOBILE.'/'.$item->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        }
        $this->actingAs($stranger)->get(self::MOBILE.'/'.$item->id)->assertForbidden();
        $this->get(self::MOBILE.'/'.$item->id.'/photo')->assertForbidden();
        $this->actingAs($this->first)->get($this->web($item).'/photo')->assertOk();
        $this->first->revokePermissionTo(AccessPermission::CUTI_APPROVE);
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->assertSame(1, $item->fresh()->current_level);
    }

    public function test_inside_radius_needs_no_line_and_creates_no_approval(): void
    {
        $this->line->delete();
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload(outside: false))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('presensi_requests', 0);
        $this->assertNull(Presensi::query()->sole()->clock_in_approval_status);
        Bus::assertNotDispatched(SendWebPushNotification::class);
    }

    public function test_missing_line_and_self_approval_cannot_bypass_approval(): void
    {
        $this->line->steps()->delete();
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasErrors('attendance');
        $this->line->steps()->create(['user_id' => $this->owner->id, 'level' => 1]);
        $this->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('presensis', 0);
        $this->assertDatabaseCount('presensi_requests', 0);
    }

    public function test_queue_snapshots_approvers_only_verified_faces_create_requests_and_duplicate_jobs_are_idempotent(): void
    {
        config(['attendance.queue_enabled' => true]);
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasNoErrors();
        $submission = AttendanceSubmission::query()->sole();
        $this->assertDatabaseCount('presensi_requests', 0);
        $this->assertSame($this->first->id, $submission->approval_context['steps'][0]['user_id']);
        $this->line->delete();
        $this->travel(10)->minutes();
        $service = app(QueuedAttendanceService::class);
        $service->process($submission->id, app(FaceVerifier::class));
        $service->process($submission->id, app(FaceVerifier::class));
        $this->assertSame('succeeded', $submission->fresh()->status);
        $this->assertDatabaseCount('presensi_requests', 1);
        $this->assertSame($this->first->id, PresensiRequest::query()->sole()->approvals->first()->user_id);
        $this->assertSame('07:00', PresensiRequest::query()->sole()->recorded_at->format('H:i'));
        $this->assertSame('pending', $submission->fresh()->statusPayload()['approval_status']);
    }

    public function test_unmatched_queued_face_produces_no_request_or_notification(): void
    {
        config(['attendance.queue_enabled' => true]);
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasNoErrors();
        $verifier = \Mockery::mock(FaceVerifier::class);
        $verifier->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(false, 20, 'test'));
        app(QueuedAttendanceService::class)->process(AttendanceSubmission::query()->sole()->id, $verifier);
        $this->assertDatabaseCount('presensi_requests', 0);
        $this->assertDatabaseCount('presensis', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_bulk_approval_rolls_back_when_any_request_is_invalid(): void
    {
        $item = $this->submit();
        $this->actingAs($this->first)->post(self::WEB.'/approve-many', ['presensi_request_ids' => [$item->id, 99999]])->assertSessionHasErrors('presensi_request_ids');
        $this->assertSame(1, $item->fresh()->current_level);
        $this->assertSame(0, $this->second->notifications()->count());
        $this->post(self::WEB.'/approve-many', ['presensi_request_ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, $item->fresh()->current_level);
    }

    public function test_notification_failure_rolls_back_attendance_and_approval_records(): void
    {
        $this->mock(PresensiNotificationService::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('notification storage failed'));
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('presensis', 0);
        $this->assertDatabaseCount('presensi_requests', 0);
        $this->assertDatabaseCount('presensi_approvals', 0);
    }

    public function test_pending_and_rejected_presensi_do_not_count_as_accepted_attendance(): void
    {
        $item = $this->submit();
        $filters = ['date_from' => '2026-10-30', 'date_to' => '2026-10-30'];
        $repository = app(PresensiRepository::class);
        $this->assertSame(0, $repository->overview($filters)['present']);
        $this->assertSame(1, $repository->paginate([...$filters, 'status' => 'approval-pending'])->total());
        $this->assertSame(0, $repository->paginate([...$filters, 'status' => 'present'])->total());
        $this->actingAs($this->owner)->get('/absensi/mobile/presensi/log?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('days.29.status', 'approval_pending')->where('days.29.flags.on_time', false));
        app(PresensiApprovalService::class)->decide($item, $this->first, ['decision' => 'approved']);
        app(PresensiApprovalService::class)->decide($item, $this->second, ['decision' => 'approved']);
        $this->assertSame(1, $repository->overview($filters)['present']);
        $this->assertSame(0, $repository->paginate([...$filters, 'status' => 'approval-pending'])->total());
        $this->travelTo('2026-10-30 15:00:00');
        $out = $this->submit('clock-out');
        app(PresensiApprovalService::class)->decide($out, $this->first, ['decision' => 'rejected', 'note' => 'Lokasi tidak sesuai']);
        $this->assertSame(0, $repository->overview($filters)['present']);
        $this->assertSame(1, $repository->paginate([...$filters, 'status' => 'approval-rejected'])->total());
    }

    public function test_admin_changes_to_the_record_cannot_be_silently_approved(): void
    {
        $item = $this->submit();
        Presensi::query()->sole()->update(['clock_in_at' => '2026-10-30 08:00:00']);
        $this->actingAs($this->first)->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame(1, $item->fresh()->current_level);
        $this->assertSame('pending', $item->fresh()->approvals->first()->status);
        $this->assertSame(0, $this->second->notifications()->count());
    }

    public function test_queue_without_approval_line_rejects_before_accepting_or_dispatching_work(): void
    {
        config(['attendance.queue_enabled' => true]);
        $this->line->delete();
        $this->actingAs($this->owner)->post('/absensi/mobile/clock-in', $this->payload())->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('attendance_submissions', 0);
        Bus::assertNotDispatched(ProcessAttendanceSubmission::class);
    }

    public function test_private_notification_channel_is_owner_only_and_available_to_web_approvers(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'secret', 'broadcasting.connections.reverb.app_id' => 'test']);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
        $this->actingAs($this->first)->postJson('/broadcasting/auth', ['channel_name' => 'private-notifications.'.$this->first->id, 'socket_id' => '123.456'])->assertOk();
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-notifications.'.$this->owner->id, 'socket_id' => '123.456'])->assertForbidden();
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-mobile.data', 'socket_id' => '123.456'])->assertForbidden();
    }
}
