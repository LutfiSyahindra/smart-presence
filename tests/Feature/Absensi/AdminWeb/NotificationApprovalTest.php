<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/notifikasi';

    private User $admin;

    private User $employee;

    private User $first;

    private User $second;

    private MasterCuti $master;

    private CutiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->startOfDay());
        $this->seed(AuthorizationSeeder::class);
        config(['webpush.public_key' => null, 'webpush.private_key' => null]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->first = User::factory()->create();
        $this->second = User::factory()->create();
        foreach ([$this->first, $this->second] as $approver) {
            $approver->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
        }
        $unit = UnitKerja::query()->create(['kode' => 'TEST', 'nama' => 'Unit Test', 'aktif' => true]);
        $jabatan = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'STF', 'nama' => 'Staf', 'aktif' => true]);
        $pegawai = Pegawai::query()->create(['nama' => 'Pemohon', 'jabatan_id' => $jabatan->id, 'unit_kerja_id' => $unit->id]);
        $pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $this->master = MasterCuti::query()->create(['kode' => 'KHUSUS', 'nama' => 'Cuti Khusus', 'durasi_hari' => 10, 'aktif' => true]);
        PegawaiCuti::query()->create(['master_cuti_id' => $this->master->id, 'pegawai_id' => $pegawai->id, 'tahun' => 0, 'jumlah_hari' => 10]);
        $line = ApprovalLine::query()->create(['type' => 'cuti']);
        $line->jabatans()->attach($jabatan->id, ['type' => 'cuti']);
        foreach ([$this->first, $this->second] as $index => $user) {
            $line->steps()->create(['user_id' => $user->id, 'level' => $index + 1]);
        }
        $this->service = app(CutiService::class);
    }

    private function submit(string $date = '2026-10-26'): CutiRequest
    {
        return $this->service->submit($this->employee, [
            'master_cuti_id' => $this->master->id,
            'tanggal_mulai' => $date, 'tanggal_selesai' => $date, 'alasan' => 'Keperluan keluarga',
        ]);
    }

    public function test_revoked_approval_permission_blocks_assigned_approver_without_hiding_the_queue(): void
    {
        $cuti = $this->submit();
        $this->first->revokePermissionTo(AccessPermission::CUTI_APPROVE);

        $this->actingAs($this->first)->get(self::URL.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.can_decide', false));
        $this->post(self::URL.'/approve-many', ['request_ids' => [$cuti->id]])->assertForbidden();
        $this->post('/absensi/admin-web/cuti/'.$cuti->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $cuti->fresh()->status);
    }

    public function test_sidebar_counts_are_scoped_to_current_approver_and_ignore_search_filters(): void
    {
        $this->submit();
        $this->submit('2026-10-27');
        $this->actingAs($this->first)->get(self::URL.'/butuh-approval?search=tidak-ada')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('adminNotificationCounts.unread_count', 2)
                ->where('adminNotificationCounts.pending_approval_count', 2)
                ->has('requests.data', 0));
        $this->getJson(self::URL.'/feed')->assertOk()
            ->assertJsonPath('unread_count', 2)->assertJsonPath('pending_approval_count', 2);
        $this->postJson(self::URL.'/read-all')->assertOk();
        $this->get(self::URL.'/semua')->assertInertia(fn (Assert $page) => $page
            ->where('adminNotificationCounts.unread_count', 0)
            ->where('adminNotificationCounts.pending_approval_count', 2));
        $this->actingAs($this->second)->getJson(self::URL.'/feed')->assertOk()
            ->assertJsonPath('unread_count', 0)->assertJsonPath('pending_approval_count', 0);
        $this->actingAs($this->admin)->getJson(self::URL.'/feed')->assertOk()
            ->assertJsonPath('unread_count', 2)->assertJsonPath('pending_approval_count', 0);
    }

    public function test_bulk_approval_advances_only_selected_requests_and_notifies_next_approver(): void
    {
        $one = $this->submit();
        $two = $this->submit('2026-10-27');
        $unselected = $this->submit('2026-10-28');
        $payload = ['request_ids' => [$two->id, $one->id]];
        $this->actingAs($this->first)->from(self::URL.'/butuh-approval')->post(self::URL.'/approve-many', $payload)
            ->assertRedirect(self::URL.'/butuh-approval')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Berhasil menyetujui 2 pengajuan pada tingkat approval Anda.');
        foreach ([$one, $two] as $cuti) {
            $this->assertSame('pending', $cuti->fresh()->status);
            $this->assertSame(2, $cuti->fresh()->current_level);
            $this->assertSame('approved', $cuti->approvals()->where('level', 1)->sole()->status);
            $this->assertSame('pending', $cuti->approvals()->where('level', 2)->sole()->status);
        }
        $this->assertSame(1, $unselected->fresh()->current_level);
        $this->assertSame('pending', $unselected->approvals()->where('level', 1)->sole()->status);
        $this->assertSame(2, $this->second->notifications()->where('data->event', 'approval_required')->count());
        $this->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 1);
        $this->actingAs($this->second)->get(self::URL.'/butuh-approval')->assertInertia(fn (Assert $page) => $page
            ->where('adminNotificationCounts.pending_approval_count', 2)->has('requests.data', 2));
        $this->post(self::URL.'/approve-many', $payload)->assertSessionHasNoErrors();
        foreach ([$one, $two] as $cuti) {
            $this->assertSame('approved', $cuti->fresh()->status);
            $this->assertNull($cuti->fresh()->current_level);
            $this->assertNotNull($cuti->fresh()->decided_at);
        }
        $this->assertSame('pending', $unselected->fresh()->status);
        $this->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 0);
    }

    public function test_stale_selection_rolls_back_approvals_notifications_and_push_jobs(): void
    {
        config(['queue.default' => 'database']);
        $one = $this->submit();
        $two = $this->submit('2026-10-27');
        $this->service->cancel($two, $this->employee);
        $notifications = DB::table('notifications')->count();
        $jobs = DB::table('jobs')->count();
        $this->actingAs($this->first)->postJson(self::URL.'/approve-many', ['request_ids' => [$one->id, $two->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame(1, $one->fresh()->current_level);
        $this->assertSame('pending', $one->approvals()->where('level', 1)->sole()->status);
        $this->assertSame('waiting', $one->approvals()->where('level', 2)->sole()->status);
        $this->assertSame('cancelled', $two->fresh()->status);
        $this->assertDatabaseCount('notifications', $notifications);
        $this->assertDatabaseCount('jobs', $jobs);
    }

    public function test_bulk_approval_does_not_allow_future_approvers_or_super_admin_override(): void
    {
        $cuti = $this->submit();
        foreach ([$this->second, $this->admin] as $actor) {
            $this->actingAs($actor)->postJson(self::URL.'/approve-many', ['request_ids' => [$cuti->id]])->assertForbidden();
        }
        $this->assertSame(1, $cuti->fresh()->current_level);
        $this->assertSame('pending', $cuti->approvals()->where('level', 1)->sole()->status);
    }

    public function test_one_unauthorized_request_rolls_back_other_selected_approvals(): void
    {
        $one = $this->submit();
        $two = $this->submit('2026-10-27');
        $two->approvals()->where('level', 1)->update(['user_id' => $this->admin->id]);
        $count = DB::table('notifications')->count();
        $this->actingAs($this->first)->postJson(self::URL.'/approve-many', ['request_ids' => [$one->id, $two->id]])->assertForbidden();
        $this->assertSame(1, $one->fresh()->current_level);
        $this->assertSame('pending', $one->approvals()->where('level', 1)->sole()->status);
        $this->assertDatabaseCount('notifications', $count);
    }

    public function test_bulk_approval_cannot_approve_own_request(): void
    {
        $cuti = $this->submit();
        $cuti->update(['user_id' => $this->first->id]);
        $this->actingAs($this->first)->getJson(self::URL.'/feed')->assertJsonPath('pending_approval_count', 0);
        $this->postJson(self::URL.'/approve-many', ['request_ids' => [$cuti->id]])->assertForbidden();
        $this->assertSame(1, $cuti->fresh()->current_level);
    }

    public function test_invalid_selections_and_repeat_submissions_do_not_change_other_requests(): void
    {
        $cuti = $this->submit();
        $this->actingAs($this->first);
        foreach ([[], 'invalid', [$cuti->id, $cuti->id], ['invalid'], [$cuti->id, 999999], range(1, 101)] as $ids) {
            $this->postJson(self::URL.'/approve-many', ['request_ids' => $ids])->assertUnprocessable();
            $this->assertSame(1, $cuti->fresh()->current_level);
        }
        $this->post(self::URL.'/approve-many', ['request_ids' => [$cuti->id]])->assertSessionHasNoErrors();
        $this->postJson(self::URL.'/approve-many', ['request_ids' => [$cuti->id]])->assertForbidden();
        $this->assertSame(2, $cuti->fresh()->current_level);
        $this->assertSame('pending', $cuti->approvals()->where('level', 2)->sole()->status);
    }

    public function test_bulk_endpoint_requires_authenticated_verified_cuti_access(): void
    {
        $cuti = $this->submit();
        $payload = ['request_ids' => [$cuti->id]];
        $this->postJson(self::URL.'/approve-many', $payload)->assertUnauthorized();
        $this->actingAs($this->employee)->postJson(self::URL.'/approve-many', $payload)->assertForbidden();
        $this->first->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->first)->post(self::URL.'/approve-many', $payload)->assertRedirect(route('verification.notice'));
        $this->assertSame(1, $cuti->fresh()->current_level);
    }
}
