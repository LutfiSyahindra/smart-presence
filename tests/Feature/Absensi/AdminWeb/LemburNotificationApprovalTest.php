<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LemburNotificationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/notifikasi';

    private User $employee;

    private User $first;

    private User $second;

    private User $admin;

    private User $stranger;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-22 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        Bus::fake([SendWebPushNotification::class, SendMobilePushNotification::class]);
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        foreach (['first', 'second', 'stranger'] as $name) {
            $this->{$name} = User::factory()->create();
            $this->{$name}->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        }
        $this->second->assignRole('pegawai');
        $this->stranger->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id]);
        $pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $line = ApprovalLine::query()->create(['type' => 'lembur']);
        $line->jabatans()->attach($position->id, ['type' => 'lembur']);
        foreach ([$this->first, $this->second] as $index => $user) {
            $line->steps()->create(['user_id' => $user->id, 'level' => $index + 1]);
        }
    }

    private function submit(string $date = '2026-10-23', array $extra = []): LemburRequest
    {
        $this->actingAs($this->employee)->post('/absensi/mobile/lembur', array_merge([
            'tanggal_lembur' => $date, 'shift_id' => $this->shift->id,
            'before_minutes' => 0, 'before_break_minutes' => 0, 'after_minutes' => 120, 'after_break_minutes' => 15,
            'compensation' => 'pay', 'alasan' => 'Laporan pelayanan',
        ], $extra))->assertSessionHasNoErrors();

        return LemburRequest::query()->latest('id')->firstOrFail();
    }

    private function cuti(): CutiRequest
    {
        $cuti = CutiRequest::query()->create([
            'user_id' => $this->employee->id, 'nama_pegawai' => 'Pemohon Cuti', 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD',
            'tanggal_mulai' => '2026-10-25', 'tanggal_selesai' => '2026-10-25', 'alasan' => 'Keluarga', 'status' => 'pending', 'current_level' => 1,
        ]);
        $cuti->approvals()->create(['user_id' => $this->first->id, 'approver_name' => $this->first->name, 'level' => 1, 'status' => 'pending']);

        return $cuti;
    }

    public function test_mobile_submission_notifies_web_only_approver_and_super_admin(): void
    {
        $lembur = $this->submit();
        $this->assertFalse($this->first->can(AccessPermission::MOBILE_ACCESS));
        $this->assertSame(1, $this->first->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, $this->second->notifications()->count());
        $this->assertSame(0, $this->stranger->notifications()->count());
        $notice = $this->first->notifications()->sole();
        $this->assertSame(self::URL.'/pengajuan/lembur/'.$lembur->id, $notice->data['url']);
        $this->assertSame('/absensi/mobile/lembur/'.$lembur->id, $notice->data['mobile_url']);
        $this->actingAs($this->first)->get(self::URL.'/butuh-approval')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.request_type', 'lembur')->where('requests.data.0.can_decide', true)
            ->where('overview.inbox', 1)->where('adminNotificationCounts.pending_approval_count', 1));
        $this->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 1);
        $this->actingAs($this->second)->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 0);
    }

    public function test_combined_queue_search_pagination_and_counts_are_scoped_to_assigned_approver(): void
    {
        $this->cuti();
        foreach (range(1, 11) as $day) {
            $this->submit('2026-11-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT));
        }
        $this->actingAs($this->first)->get(self::URL.'/butuh-approval?page=2')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 12)->has('requests.data', 2)->where('overview.inbox', 12));
        $this->get(self::URL.'/butuh-approval?search=Nadia')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 11)->where('overview.inbox', 12)->where('adminNotificationCounts.pending_approval_count', 12));
        $this->get(self::URL.'/butuh-approval?search=Pemohon')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.request_type', 'cuti'));
        $this->actingAs($this->stranger)->get(self::URL.'/butuh-approval')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $this->actingAs($this->admin)->get(self::URL.'/butuh-approval')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    }

    public function test_web_and_mobile_decisions_share_the_same_approval_sequence(): void
    {
        $lembur = $this->submit();
        $detail = self::URL.'/pengajuan/lembur/'.$lembur->id;
        $this->actingAs($this->first)->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/LemburDetail')->where('lembur.can_decide', true)->where('lembur.duration_minutes', 105));
        $this->from($detail)->post($detail.'/decision', ['decision' => 'approved'])->assertRedirect($detail)->assertSessionHasNoErrors();
        $this->assertSame(2, $lembur->fresh()->current_level);
        $this->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 0);
        $this->post($detail.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->second)->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 1);
        $this->post('/absensi/mobile/lembur/'.$lembur->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $lembur->fresh()->status);
        $this->actingAs($this->first)->get(self::URL.'/disetujui')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.request_type', 'lembur')->where('requests.data.0.can_decide', false));
    }

    public function test_lembur_history_keeps_notification_access_after_line_is_removed_without_cuti_access(): void
    {
        $this->submit();
        ApprovalLine::query()->where('type', 'lembur')->delete();
        $this->actingAs($this->first)->get(self::URL.'/butuh-approval')->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
        $this->get('/absensi/admin-web/cuti')->assertForbidden();
        $this->get('/absensi/admin-web/dashboard')->assertForbidden();
    }

    public function test_lembur_only_bulk_approval_validates_ids_and_uses_the_assigned_level(): void
    {
        $lembur = $this->submit();
        $this->actingAs($this->first);
        foreach ([[], [$lembur->id, $lembur->id], ['invalid'], [999999], range(1, 101)] as $ids) {
            $this->postJson(self::URL.'/approve-many', ['request_ids' => [], 'lembur_request_ids' => $ids])->assertUnprocessable();
            $this->assertSame(1, $lembur->fresh()->current_level);
        }
        $this->post(self::URL.'/approve-many', ['request_ids' => [], 'lembur_request_ids' => [$lembur->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, $lembur->fresh()->current_level);
    }

    public function test_notification_links_open_web_detail_including_existing_mobile_payloads(): void
    {
        $lembur = $this->submit();
        $notice = $this->first->notifications()->sole();
        $notice->update(['data' => array_merge($notice->data, ['url' => '/absensi/mobile/lembur/'.$lembur->id])]);
        $this->actingAs($this->first)->get(self::URL.'/open/'.$notice->id)->assertRedirect(self::URL.'/pengajuan/lembur/'.$lembur->id);
        $this->assertNotNull($notice->fresh()->read_at);
        $this->actingAs($this->stranger)->get(self::URL.'/open/'.$notice->id)->assertNotFound();
        $this->get(self::URL.'/pengajuan/lembur/'.$lembur->id)->assertForbidden();
    }

    public function test_web_rejection_requires_note_and_blocks_other_users_and_revoked_permissions(): void
    {
        $lembur = $this->submit();
        $url = self::URL.'/pengajuan/lembur/'.$lembur->id;
        foreach ([$this->second, $this->stranger, $this->admin] as $user) {
            $this->actingAs($user)->post($url.'/decision', ['decision' => 'approved'])->assertForbidden();
        }
        $this->first->revokePermissionTo(AccessPermission::CUTI_APPROVE);
        $this->actingAs($this->first)->get($url)->assertInertia(fn (Assert $page) => $page->where('lembur.can_decide', false));
        $this->post($url.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->first->givePermissionTo(AccessPermission::CUTI_APPROVE);
        $this->post($url.'/decision', ['decision' => 'rejected', 'note' => '  '])->assertSessionHasErrors('note');
        $this->post($url.'/decision', ['decision' => 'rejected', 'note' => 'Jadwal perlu diperbaiki'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $lembur->fresh()->status);
        $this->assertSame('skipped', $lembur->approvals()->where('level', 2)->sole()->status);
        $this->actingAs($this->employee)->getJson('/absensi/mobile/notifikasi/feed')->assertJsonPath('unread_count', 1);
    }

    public function test_web_attachments_require_request_access_without_mobile_permission(): void
    {
        $lembur = $this->submit(extra: ['attachments' => [UploadedFile::fake()->createWithContent('laporan.txt', 'Laporan lembur')]]);
        $url = self::URL.'/pengajuan/lembur/'.$lembur->id.'/lampiran/0';
        $this->actingAs($this->first)->get($url)->assertOk()->assertDownload('laporan.txt');
        $this->get($url.'?preview=1')->assertOk()->assertStreamedContent('Laporan lembur');
        $this->actingAs($this->stranger)->get($url)->assertForbidden();
    }

    public function test_bulk_approval_keeps_cuti_and_lembur_with_identical_ids_separate(): void
    {
        $cuti = $this->cuti();
        $lembur = $this->submit();
        $other = $this->submit('2026-10-24');
        $this->assertSame($cuti->id, $lembur->id);
        $this->actingAs($this->first)->post(self::URL.'/approve-many', [
            'request_ids' => [$cuti->id], 'lembur_request_ids' => [$lembur->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Berhasil menyetujui 2 pengajuan pada tingkat approval Anda.');
        $this->assertSame('approved', $cuti->fresh()->status);
        $this->assertSame(2, $lembur->fresh()->current_level);
        $this->assertSame(1, $other->fresh()->current_level);
    }

    public function test_invalid_lembur_in_mixed_batch_rolls_back_cuti_and_notifications(): void
    {
        $cuti = $this->cuti();
        $lembur = $this->submit();
        app(LemburService::class)->cancel($lembur, $this->employee);
        $count = $this->admin->notifications()->count();
        $this->actingAs($this->first)->postJson(self::URL.'/approve-many', [
            'request_ids' => [$cuti->id], 'lembur_request_ids' => [$lembur->id],
        ])->assertForbidden();
        $this->assertSame('pending', $cuti->fresh()->status);
        $this->assertSame('pending', $cuti->approvals()->sole()->status);
        $this->assertSame($count, $this->admin->notifications()->count());
    }
}
