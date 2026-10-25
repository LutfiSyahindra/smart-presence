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
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class PerubahanShiftApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '/absensi/mobile/perubahan-shift';
    private const WEB = '/absensi/admin-web/notifikasi';

    private User $owner;
    private User $first;
    private User $second;
    private User $admin;
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
        Bus::fake([SendMobilePushNotification::class, SendWebPushNotification::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->owner->assignRole('pegawai');
        $this->first = User::factory()->create(['name' => 'Approver Web']);
        $this->first->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        $this->second = User::factory()->create(['name' => 'Approver Mobile']);
        $this->second->assignRole('pegawai');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->original = Shift::query()->create(['nama' => 'Shift Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->target = Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'unit_kerja_id' => $unit->id,
            'jabatan_id' => $position->id, 'shift_id' => $this->original->id, 'tanggal_mulai_bekerja' => '2026-10-01']);
        $this->pegawai->forceFill(['user_id' => $this->owner->id])->save();
        $this->line = ApprovalLine::query()->create(['type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT]);
        $this->line->jabatans()->attach($position->id, ['type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT]);
        foreach ([$this->first, $this->second] as $i => $approver) {
            $this->line->steps()->create(['user_id' => $approver->id, 'level' => $i + 1]);
        }
    }

    private function submit(string $date = '2026-10-26'): PerubahanShiftRequest
    {
        $this->actingAs($this->owner)->post(self::MOBILE, ['tanggal_perubahan' => $date, 'original_shift_id' => $this->original->id,
            'requested_shift_id' => $this->target->id, 'alasan' => 'Keperluan keluarga'])->assertSessionHasNoErrors();

        return PerubahanShiftRequest::query()->latest('id')->firstOrFail();
    }

    private function web(PerubahanShiftRequest $item): string
    {
        return self::WEB.'/pengajuan/perubahan-shift/'.$item->id;
    }

    public function test_web_then_mobile_approval_updates_only_the_requested_date_and_notifies_every_stage(): void
    {
        $item = $this->submit();
        $this->assertFalse($this->first->can(AccessPermission::MOBILE_ACCESS));
        $this->actingAs($this->first)->get($this->web($item))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/PerubahanShiftDetail')->where('shiftRequest.can_decide', true));
        $this->get(self::WEB.'/butuh-approval?request_type=perubahan-shift')->assertInertia(fn (Assert $page) => $page
            ->where('requestTypeCounts.perubahan-shift', 1)->where('requests.total', 1)
            ->where('requests.data.0.request_type', 'perubahan-shift')->where('requests.data.0.can_decide', true)
            ->where('adminNotificationCounts.pending_approval_count', 1));
        $this->getJson(self::WEB.'/feed')->assertJsonPath('pending_approval_count', 1);
        $this->post($this->web($item).'/decision', ['decision' => 'approved', 'note' => 'Sesuai jadwal unit'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame(2, $item->fresh()->current_level);
        $this->assertDatabaseCount('presensis', 0);
        $this->assertSame(1, $this->second->notifications()->where('data->event', 'approval_required')->count());
        $this->getJson(self::WEB.'/feed')->assertJsonPath('pending_approval_count', 0);
        $this->actingAs($this->second)->get(self::MOBILE.'/'.$item->id)->assertInertia(fn (Assert $page) => $page
            ->where('initialRequest.can_decide', true)->where('initialRequest.current_level', 2));
        $this->post(self::MOBILE.'/'.$item->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertNull($item->fresh()->current_level);
        $this->assertNotNull($item->fresh()->decided_at);
        $this->assertSame(['approved', 'approved'], $item->fresh()->approvals->pluck('status')->all());
        $record = Presensi::query()->sole();
        $this->assertSame('2026-10-26', $record->tanggal_presensi->toDateString());
        $this->assertSame($this->target->id, $record->shift_id);
        $this->assertNull($record->clock_in_at);
        $this->assertNull($record->clock_out_at);
        $this->assertSame($this->original->id, $this->pegawai->fresh()->shift_id);
        $notice = $this->owner->notifications()->where('data->event', 'approved')->sole();
        $this->assertSame('Pengajuan perubahan shift disetujui', $notice->data['title']);
        $this->assertStringNotContainsString('Dibatalkan', $notice->data['message']);
        $this->actingAs($this->first)->get(self::WEB.'/disetujui')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.can_decide', false)->where('overview.approved', 1));
        $this->travelTo('2026-10-26 09:00:00');
        $this->actingAs($this->owner)->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('todaySchedule.shift_name', 'Shift Malam')->where('todaySchedule.start_time', '22:00')->where('todaySchedule.end_time', '06:00'));
        $this->get('/absensi/mobile/presensi/log')->assertInertia(fn (Assert $page) => $page->where('days.25.shift_name', 'Shift Malam'));
        $this->travelTo('2026-10-27 09:00:00');
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('todaySchedule.shift_name', 'Shift Pagi'));
    }

    public function test_unassigned_super_admin_owner_and_later_approver_cannot_decide(): void
    {
        $item = $this->submit();
        foreach ([$this->owner, $this->second, $this->admin] as $user) {
            $this->actingAs($user)->post(self::MOBILE.'/'.$item->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        }
        $this->actingAs($this->admin)->get($this->web($item))->assertInertia(fn (Assert $page) => $page->where('shiftRequest.can_decide', false));
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->first->revokePermissionTo(AccessPermission::CUTI_APPROVE);
        $this->actingAs($this->first)->get($this->web($item))->assertInertia(fn (Assert $page) => $page->where('shiftRequest.can_decide', false));
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_rejection_requires_a_note_skips_remaining_approvers_and_notifies_owner(): void
    {
        $item = $this->submit();
        $this->actingAs($this->first);
        foreach ([['decision' => 'rejected', 'note' => '  '], ['decision' => 'cancelled'], ['decision' => 'approved', 'note' => str_repeat('a', 1001)]] as $data) {
            $this->post($this->web($item).'/decision', $data)->assertSessionHasErrors();
        }
        $this->post($this->web($item).'/decision', ['decision' => 'rejected', 'note' => '  Jadwal pelayanan belum memungkinkan.  '])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertSame(['rejected', 'skipped'], $item->fresh()->approvals->pluck('status')->all());
        $this->assertSame('Jadwal pelayanan belum memungkinkan.', $item->fresh()->approvals->first()->note);
        $this->assertSame(1, $this->owner->notifications()->where('data->event', 'rejected')->count());
        $this->assertDatabaseCount('presensis', 0);
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertForbidden();
    }

    public function test_cancel_after_partial_approval_prevents_any_later_decision(): void
    {
        $item = $this->submit();
        $this->actingAs($this->first)->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(self::MOBILE.'/'.$item->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame(['approved', 'skipped'], $item->fresh()->approvals->pluck('status')->all());
        $this->actingAs($this->second)->post(self::MOBILE.'/'.$item->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_final_approval_revalidates_master_schedule_attendance_and_expired_dates(): void
    {
        $this->line->steps()->where('level', 2)->delete();
        $item = $this->submit();
        $this->target->update(['jam_masuk' => '23:00']);
        $this->actingAs($this->first)->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->target->update(['jam_masuk' => '22:00']);
        $this->pegawai->update(['shift_id' => $this->target->id]);
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->pegawai->update(['shift_id' => $this->original->id]);
        $record = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'tanggal_presensi' => '2026-10-26', 'shift_id' => $this->original->id,
            'clock_in_at' => '2026-10-26 08:00']);
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame($this->original->id, $record->fresh()->shift_id);
        $record->delete();
        $this->travelTo('2026-10-26 08:00:00');
        $this->post($this->web($item).'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame('pending', $item->fresh()->approvals->first()->status);
        $this->assertDatabaseCount('presensis', 0);
        $this->post($this->web($item).'/decision', ['decision' => 'rejected', 'note' => 'Tanggal pengajuan telah lewat.'])->assertSessionHasNoErrors();
    }

    public function test_shift_bulk_selection_is_isolated_from_cuti_with_identical_ids_and_is_atomic(): void
    {
        $this->line->steps()->where('level', 2)->delete();
        $first = $this->submit();
        $second = $this->submit('2026-10-27');
        $cuti = CutiRequest::query()->create(['user_id' => $this->owner->id, 'nama_pegawai' => 'Cuti', 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-10-28', 'tanggal_selesai' => '2026-10-28', 'alasan' => 'Keluarga', 'status' => 'pending', 'current_level' => 1]);
        $this->assertSame($first->id, $cuti->id);
        $this->actingAs($this->first)->post(self::WEB.'/approve-many', ['perubahan_shift_request_ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('pending', $cuti->fresh()->status);
        foreach ([[], [$second->id, $second->id], [999999], range(1, 101)] as $ids) {
            $this->postJson(self::WEB.'/approve-many', ['perubahan_shift_request_ids' => $ids])->assertUnprocessable();
        }
        $third = $this->submit('2026-10-28');
        $this->actingAs($this->owner)->post(self::MOBILE.'/'.$third->id.'/cancel')->assertSessionHasNoErrors();
        $count = $this->admin->notifications()->count();
        $this->actingAs($this->first)->post(self::WEB.'/approve-many', ['perubahan_shift_request_ids' => [$second->id, $third->id]])->assertForbidden();
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertDatabaseCount('presensis', 1);
        $this->assertSame($count, $this->admin->notifications()->count());
    }

    public function test_notification_failure_rolls_back_final_approval_and_the_dated_schedule(): void
    {
        $this->line->steps()->where('level', 2)->delete();
        $item = $this->submit();
        $this->mock(PerubahanShiftNotificationService::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('Notification failed')));
        try {
            app(PerubahanShiftService::class)->decide($item, $this->first, ['decision' => 'approved']);
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        }
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame('pending', $item->fresh()->approvals->first()->status);
        $this->assertDatabaseCount('presensis', 0);
    }
}
