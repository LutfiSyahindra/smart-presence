<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\PerubahanShiftService;
use App\Services\Notifications\PerubahanShiftNotificationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class PerubahanShiftTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/perubahan-shift';

    private User $employee;

    private User $approver;

    private Pegawai $pegawai;

    private Shift $original;

    private Shift $target;

    private ApprovalLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-23 07:00:00');
        $this->seed(AuthorizationSeeder::class);
        Bus::fake([SendWebPushNotification::class, SendMobilePushNotification::class]);
        Http::preventStrayRequests();
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->approver = User::factory()->create(['name' => 'Kepala Ruangan']);
        $this->approver->assignRole('pegawai');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->original = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->target = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'unit_kerja_id' => $unit->id,
            'jabatan_id' => $position->id, 'shift_id' => $this->original->id]);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $this->line = ApprovalLine::query()->create(['type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT]);
        $this->line->jabatans()->attach($position->id, ['type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT]);
        $this->line->steps()->create(['user_id' => $this->approver->id, 'level' => 1]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['tanggal_perubahan' => '2026-10-26', 'original_shift_id' => $this->original->id,
            'requested_shift_id' => $this->target->id, 'alasan' => 'Menyesuaikan keperluan keluarga.'], $overrides);
    }

    private function submit(array $overrides = []): PerubahanShiftRequest
    {
        $data = $this->payload($overrides);
        $this->actingAs($this->employee)->from(self::URL)->post(self::URL, $data)
            ->assertSessionHasNoErrors()->assertRedirect(self::URL.'?month='.substr($data['tanggal_perubahan'], 0, 7));

        return PerubahanShiftRequest::query()->latest('id')->firstOrFail();
    }

    public function test_empty_page_has_real_employee_shift_and_separate_shift_approval_line(): void
    {
        $this->actingAs($this->employee)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/PerubahanShift')->where('month', '2026-10')->where('today', '2026-10-23')
            ->where('summary', ['count' => 0, 'pending' => 0])->has('requests.data', 0)
            ->where('employee.name', 'Nadia Putri')->where('currentShift.id', $this->original->id)
            ->has('shifts', 2)->where('approvalPreview.0.name', 'Kepala Ruangan')->where('unavailableReason', null));
    }

    public function test_submission_saves_snapshots_and_ignores_client_identity_and_status_without_changing_schedule(): void
    {
        $other = User::factory()->create();
        $second = User::factory()->create(['name' => 'Kepala Bidang']);
        $second->assignRole('pegawai');
        $this->line->steps()->create(['user_id' => $second->id, 'level' => 2]);
        $item = $this->submit(['alasan' => '  Keperluan keluarga.  ', 'status' => 'approved', 'user_id' => $other->id, 'current_level' => 3]);
        $this->assertSame($this->employee->id, $item->user_id);
        $this->assertSame($this->pegawai->id, $item->pegawai_id);
        $this->assertSame('pending', $item->status);
        $this->assertSame(1, $item->current_level);
        $this->assertSame('Keperluan keluarga.', $item->alasan);
        $this->assertSame('Malam', $item->requested_shift['name']);
        $this->assertTrue($item->requested_shift['next_day']);
        $this->assertSame(['pending', 'waiting'], $item->approvals->pluck('status')->all());
        $this->assertSame(['Kepala Ruangan', 'Kepala Bidang'], $item->approvals->pluck('approver_name')->all());
        $this->assertSame($this->original->id, $this->pegawai->fresh()->shift_id);
        $this->assertDatabaseCount('presensis', 0);
        $this->target->update(['nama' => 'Nama baru', 'jam_masuk' => '23:00']);
        $this->approver->update(['name' => 'Nama approver baru']);
        $this->line->steps()->delete();
        $this->assertSame('Malam', $item->fresh()->requested_shift['name']);
        $this->assertSame('Kepala Ruangan', $item->fresh()->approvals->first()->approver_name);
    }

    public function test_duplicate_submission_does_not_create_another_request_or_approval(): void
    {
        $this->submit();
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('tanggal_perubahan');
        $this->assertDatabaseCount('perubahan_shift_requests', 1);
        $this->assertDatabaseCount('perubahan_shift_approvals', 1);
    }

    public function test_list_filters_by_date_and_status_without_exposing_another_users_requests(): void
    {
        $first = $this->submit();
        $this->submit(['tanggal_perubahan' => '2026-10-27'])->update(['status' => 'rejected', 'current_level' => null]);
        $this->submit(['tanggal_perubahan' => '2026-11-01']);
        $this->submit(['tanggal_perubahan' => '2026-10-28'])->update(['user_id' => $this->approver->id]);
        $this->actingAs($this->employee)->get(self::URL.'?month=2026-10&status=pending')->assertInertia(fn (Assert $page) => $page
            ->where('summary', ['count' => 2, 'pending' => 1])->where('requests.total', 1)
            ->where('requests.data.0.id', $first->id)->where('requests.data.0.date', '2026-10-26')
            ->where('requests.data.0.original_shift.name', 'Pagi')->where('requests.data.0.approvals.0.name', 'Kepala Ruangan'));
        $this->get(self::URL.'?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
    }

    public function test_invalid_payloads_do_not_save_any_requests(): void
    {
        foreach ([
            ['tanggal_perubahan' => '2026-10-22'], ['tanggal_perubahan' => '2026-02-30'], ['tanggal_perubahan' => '2101-01-01'],
            ['requested_shift_id' => $this->original->id], ['requested_shift_id' => 999999], ['original_shift_id' => 999999],
            ['alasan' => '   '], ['alasan' => str_repeat('a', 201)], ['alasan' => ['unexpected']],
        ] as $changes) {
            $this->actingAs($this->employee)->post(self::URL, $this->payload($changes))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
    }

    public function test_stale_shift_and_recorded_attendance_are_rejected(): void
    {
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['original_shift_id' => $this->target->id,
            'requested_shift_id' => $this->original->id]))->assertSessionHasErrors('original_shift_id');
        Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->original->id,
            'tanggal_presensi' => '2026-10-26', 'clock_in_at' => '2026-10-26 08:00']);
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('tanggal_perubahan');
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
    }

    public function test_today_is_allowed_only_before_both_shifts_have_started(): void
    {
        $this->submit(['tanggal_perubahan' => '2026-10-23']);
        $this->travelTo('2026-10-24 08:00:00');
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['tanggal_perubahan' => '2026-10-24']))
            ->assertSessionHasErrors('tanggal_perubahan');
        $this->travelTo('2026-10-25 07:00:00');
        $this->target->update(['jam_masuk' => '06:00']);
        $this->post(self::URL, $this->payload(['tanggal_perubahan' => '2026-10-25']))->assertSessionHasErrors('tanggal_perubahan');
        $this->assertDatabaseCount('perubahan_shift_requests', 1);
    }

    public function test_approved_leave_blocks_the_date_but_pending_leave_does_not(): void
    {
        $leave = CutiRequest::query()->create([
            'user_id' => $this->employee->id, 'pegawai_id' => $this->pegawai->id,
            'jabatan_id' => $this->pegawai->jabatan_id, 'nama_pegawai' => 'Nadia', 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-10-25', 'tanggal_selesai' => '2026-10-27', 'alasan' => 'Keperluan keluarga', 'status' => 'approved',
        ]);
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('tanggal_perubahan');
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
        $leave->update(['status' => 'pending']);
        $this->submit();
    }

    public function test_three_approval_steps_are_saved_and_a_broken_step_order_is_rejected(): void
    {
        foreach ([2, 3] as $level) {
            $approver = User::factory()->create();
            $approver->assignRole('pegawai');
            $this->line->steps()->create(['user_id' => $approver->id, 'level' => $level]);
        }
        $item = $this->submit();
        $this->assertSame([1, 2, 3], $item->approvals->pluck('level')->all());
        $this->assertSame(['pending', 'waiting', 'waiting'], $item->approvals->pluck('status')->all());
        $this->line->steps()->where('level', 2)->delete();
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['tanggal_perubahan' => '2026-10-27']))
            ->assertSessionHasErrors('pengajuan');
        $this->assertDatabaseCount('perubahan_shift_requests', 1);
    }

    public function test_missing_wrong_type_or_self_approval_lines_block_submission(): void
    {
        $this->line->update(['type' => ApprovalLine::TYPE_LEMBUR]);
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->line->update(['type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT]);
        $this->line->steps()->update(['user_id' => $this->employee->id]);
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->line->steps()->delete();
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
    }

    public function test_inactive_position_missing_profile_and_ineligible_approver_are_explained(): void
    {
        $this->approver->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->approver->forceFill(['email_verified_at' => now()])->save();
        $this->approver->removeRole('pegawai');
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->approver->assignRole('pegawai');
        $this->pegawai->jabatan->update(['aktif' => false]);
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->pegawai->forceFill(['user_id' => null])->save();
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->where('employee', null)
            ->where('unavailableReason', 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.'));
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('pengajuan');
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
    }

    public function test_routes_require_authentication_verification_and_mobile_access(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
        $this->post(self::URL, $this->payload())->assertRedirect('/login');
        $other = User::factory()->create();
        $this->actingAs($other)->get(self::URL)->assertForbidden();
        $this->post(self::URL, $this->payload())->assertForbidden();
        $this->employee->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->employee)->get(self::URL)->assertRedirect('/verify-email');
        $this->post(self::URL, $this->payload())->assertRedirect('/verify-email');
    }

    public function test_submission_notifies_current_approver_and_admin_with_working_owned_links(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $second = User::factory()->create();
        $second->assignRole('pegawai');
        $this->line->steps()->create(['user_id' => $second->id, 'level' => 2]);
        $item = $this->submit();
        $this->assertSame(1, $this->approver->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $second->notifications()->count());
        $this->assertSame(0, $this->employee->notifications()->count());
        $notice = $this->approver->notifications()->sole();
        $this->assertSame('perubahan-shift', $notice->data['request_type']);
        $this->assertSame('submitted', $notice->data['event']);
        $this->assertSame(self::URL.'/'.$item->id, $notice->data['mobile_url']);
        Bus::assertDispatched(SendWebPushNotification::class, 2);
        Bus::assertDispatched(SendMobilePushNotification::class, 2);
        $this->actingAs($second)->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertNotFound();
        $this->assertNull($notice->fresh()->read_at);
        $this->actingAs($this->approver)->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertRedirect(self::URL.'/'.$item->id);
        $this->assertNotNull($notice->fresh()->read_at);
        $this->get(self::URL.'/'.$item->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialRequest.id', $item->id)->where('initialRequest.can_cancel', false)->where('initialRequest.name', 'Nadia Putri'));
        $adminNotice = $admin->notifications()->sole();
        $this->actingAs($admin)->get('/absensi/admin-web/notifikasi/open/'.$adminNotice->id)
            ->assertRedirect('/absensi/admin-web/notifikasi/pengajuan/perubahan-shift/'.$item->id);
        $this->get($adminNotice->data['url'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/PerubahanShiftDetail')->where('shiftRequest.can_decide', false));
    }

    public function test_inbox_and_delegation_counts_only_include_current_assigned_pending_requests(): void
    {
        $first = $this->submit();
        $second = $this->submit(['tanggal_perubahan' => '2026-11-02']);
        $this->actingAs($this->approver)->get('/absensi/mobile/notifikasi?tab=approvals')->assertInertia(fn (Assert $page) => $page
            ->where('perubahanShiftPendingApprovalCount', 2)->where('pendingApprovalCount', 2));
        $this->get('/absensi/mobile/notifikasi?category=perubahan-shift')->assertRedirect('/absensi/mobile/notifikasi/perubahan-shift');
        $this->get('/absensi/mobile/notifikasi/perubahan-shift')->assertRedirect(self::URL.'?scope=delegation');
        $this->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page
            ->where('allPeriods', true)->where('requests.total', 2)->where('requests.data.0.id', $second->id)
            ->where('requests.data.0.can_cancel', false)->where('approvalCount', 2));
        $this->get(self::URL.'?scope=delegation&month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('allPeriods', false)->where('requests.total', 1)->where('requests.data.0.id', $first->id));
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $this->actingAs($this->employee)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $stranger = User::factory()->create();
        $stranger->assignRole('pegawai');
        $this->actingAs($stranger)->get(self::URL.'/'.$first->id)->assertForbidden();
    }

    public function test_owner_can_cancel_pending_request_notify_approvers_and_submit_again_for_same_date(): void
    {
        $future = User::factory()->create();
        $future->assignRole('pegawai');
        $this->line->steps()->create(['user_id' => $future->id, 'level' => 2]);
        $item = $this->submit();
        $this->actingAs($this->employee)->get(self::URL.'/'.$item->id)->assertInertia(fn (Assert $page) => $page
            ->where('initialRequest.can_cancel', true));
        $this->post(self::URL.'/'.$item->id.'/cancel')->assertSessionHasNoErrors()->assertRedirect(self::URL.'?month=2026-10');
        $this->assertSame('cancelled', $item->fresh()->status);
        $this->assertNull($item->fresh()->current_level);
        $this->assertNotNull($item->fresh()->cancelled_at);
        $this->assertSame(['skipped', 'skipped'], $item->fresh()->approvals->pluck('status')->all());
        $this->assertSame(1, $this->approver->notifications()->where('data->event', 'cancelled')->count());
        $this->assertSame(1, $future->notifications()->where('data->event', 'cancelled')->count());
        $this->assertSame(0, $this->employee->notifications()->count());
        $this->assertSame($this->original->id, $this->pegawai->fresh()->shift_id);
        $this->assertDatabaseCount('presensis', 0);
        $this->actingAs($this->approver)->get('/absensi/mobile/notifikasi')->assertInertia(fn (Assert $page) => $page
            ->where('pendingApprovalCount', 0)->where('perubahanShiftPendingApprovalCount', 0));
        $notice = $this->approver->notifications()->where('data->event', 'submitted')->sole();
        $this->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertRedirect(self::URL.'/'.$item->id);
        $this->get(self::URL.'/'.$item->id)->assertInertia(fn (Assert $page) => $page->where('initialRequest.status', 'cancelled')
            ->where('initialRequest.can_cancel', false));
        $this->actingAs($this->employee)->get(self::URL.'?status=cancelled')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('summary.pending', 0));
        $this->submit();
        $this->assertDatabaseCount('perubahan_shift_requests', 2);
    }

    public function test_only_owner_can_cancel_and_finalized_requests_cannot_be_cancelled_twice(): void
    {
        $item = $this->submit();
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        foreach ([$this->approver, $admin] as $user) {
            $this->actingAs($user)->post(self::URL.'/'.$item->id.'/cancel')->assertForbidden();
        }
        foreach (['approved', 'rejected', 'cancelled'] as $status) {
            $item->update(['status' => $status]);
            $this->actingAs($this->employee)->post(self::URL.'/'.$item->id.'/cancel')->assertSessionHasErrors('cancel');
            $this->assertSame($status, $item->fresh()->status);
        }
        $this->assertSame(0, $this->approver->notifications()->where('data->event', 'cancelled')->count());
    }

    public function test_cancellation_preserves_completed_approvals_and_does_not_need_a_current_approval_line(): void
    {
        $item = $this->submit();
        $item->approvals()->first()->update(['status' => 'approved', 'note' => 'Sudah ditinjau', 'decided_at' => now()]);
        $this->line->delete();
        $this->pegawai->update(['jabatan_id' => null]);
        $this->actingAs($this->employee)->post(self::URL.'/'.$item->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->approvals->first()->status);
        $this->assertSame('Sudah ditinjau', $item->fresh()->approvals->first()->note);
        $this->assertSame('cancelled', $item->fresh()->status);
    }

    public function test_notification_failure_rolls_back_submission_and_cancellation(): void
    {
        $this->mock(PerubahanShiftNotificationService::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('Notification failed')));
        try {
            app(PerubahanShiftService::class)->submit($this->employee, $this->payload());
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('perubahan_shift_requests', 0);
        $this->assertDatabaseCount('perubahan_shift_approvals', 0);
        $this->app->forgetInstance(PerubahanShiftNotificationService::class);
        $item = $this->submit();
        $this->mock(PerubahanShiftNotificationService::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('Notification failed')));
        try {
            app(PerubahanShiftService::class)->cancel($item, $this->employee);
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        }
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame('pending', $item->fresh()->approvals->first()->status);
        $this->assertNull($item->fresh()->cancelled_at);
    }

    public function test_invalid_months_and_statuses_are_rejected(): void
    {
        foreach (['month=2026-13', 'month=1899-12', 'month=2101-01', 'status=unknown'] as $query) {
            $this->actingAs($this->employee)->getJson(self::URL.'?'.$query)->assertUnprocessable();
        }
    }
}
