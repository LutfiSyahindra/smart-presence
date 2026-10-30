<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CutiPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/cuti';

    private User $employee;

    private User $approver;

    private Pegawai $pegawai;

    private MasterCuti $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-19 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->approver = User::factory()->create();
        $this->approver->assignRole('pegawai');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'Instalasi Gawat Darurat', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id]);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $line = ApprovalLine::query()->create(['type' => 'cuti']);
        $line->jabatans()->attach($position->id, ['type' => 'cuti']);
        $line->steps()->create(['user_id' => $this->approver->id, 'level' => 1]);
        $this->annual = MasterCuti::query()->create(['kode' => 'CT', 'nama' => 'Cuti Tahunan', 'jenis' => 'tahunan', 'durasi_hari' => 12, 'aktif' => true]);
        PegawaiCuti::query()->create(['master_cuti_id' => $this->annual->id, 'pegawai_id' => $this->pegawai->id, 'tahun' => 2026, 'jumlah_hari' => 12]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['master_cuti_id' => $this->annual->id, 'tanggal_mulai' => '2026-10-26', 'tanggal_selesai' => '2026-10-27', 'alasan' => 'Keperluan keluarga'], $overrides);
    }

    private function submit(array $overrides = []): CutiRequest
    {
        $this->actingAs($this->employee)->post(self::URL, $this->payload($overrides))
            ->assertSessionHasNoErrors()->assertRedirect(self::URL.'?month='.substr($overrides['tanggal_mulai'] ?? '2026-10-26', 0, 7));

        return CutiRequest::query()->latest('id')->firstOrFail();
    }

    public function test_approved_cuti_cancellation_obeys_configuration_and_returns_the_balance(): void
    {
        $cuti = $this->submit();
        $service = app(\App\Services\Absensi\AdminWeb\Cuti\CutiService::class);
        $service->decide($cuti, $this->approver, ['decision' => 'approved']);
        $this->actingAs($this->employee)->post(self::URL."/{$cuti->id}/cancel")->assertSessionHasErrors('cancel');
        app(\App\Services\Absensi\ConfigurationService::class)->update(['cancel_approved_cuti' => true], $this->approver->id);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('requests.data.0.can_cancel', true)->where('balance.used', 2));
        $this->post(self::URL."/{$cuti->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $cuti->fresh()->status);
        $this->assertSame('approved', $cuti->fresh()->approvals->first()->status);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('balance.used', 0)->where('balance.available', 12));
    }

    public function test_past_cuti_approval_and_rejection_obey_configuration(): void
    {
        $cuti = $this->submit();
        $this->travelTo('2026-10-28 09:00');
        app(\App\Services\Absensi\ConfigurationService::class)->update(['approve_past_requests' => false], $this->approver->id);
        $this->actingAs($this->approver)->post('/absensi/mobile/notifikasi/cuti/approve-many', ['request_ids' => [$cuti->id]])->assertSessionHasErrors('decision');
        $this->post(self::URL."/{$cuti->id}/decision", ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $cuti->fresh()->status);
        app(\App\Services\Absensi\ConfigurationService::class)->update(['approve_past_requests' => true], $this->approver->id);
        $this->post(self::URL."/{$cuti->id}/decision", ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $cuti->fresh()->status);
    }

    public function test_late_approval_counts_leave_on_the_requested_date_in_the_previous_month(): void
    {
        $this->travelTo('2026-10-30 09:00');
        $shift = \App\Models\Shift::query()->create(['nama' => 'Shift Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->pegawai->forceFill(['shift_id' => $shift->id, 'shift_assigned_at' => '2026-10-01',
            'tanggal_mulai_bekerja' => '2026-10-01'])->save();
        $cuti = $this->submit(['tanggal_mulai' => '2026-10-30', 'tanggal_selesai' => '2026-10-30']);
        $this->travelTo('2026-11-03 09:00');
        $this->actingAs($this->employee)->get('/absensi/mobile/presensi/log?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('days.29.date', '2026-10-30')
                ->where('days.29.status', 'absent')->where('days.29.flags.leave', false));
        app(\App\Services\Absensi\ConfigurationService::class)->update(['approve_past_requests' => true], $this->approver->id);
        $this->actingAs($this->approver)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-03', $cuti->fresh()->decided_at->toDateString());
        $this->assertSame('2026-10-30', $cuti->fresh()->tanggal_mulai->toDateString());
        $this->actingAs($this->employee)->get('/absensi/mobile/presensi/log?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('days.29.status', 'leave')
                ->where('days.29.flags.leave', true)->where('days.29.flags.absent', false)->where('summary.leave', 1));
        $this->get('/absensi/mobile/presensi/log?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('summary.leave', 0));
        $this->get(self::URL.'?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('balance.used', 1)->where('balance.available', 11));
        $this->assertDatabaseCount('presensis', 0);
    }

    public function test_mobile_employee_can_view_and_submit_without_admin_access(): void
    {
        $this->assertFalse($this->employee->can(AccessPermission::ADMIN_ACCESS));
        $this->actingAs($this->employee)->get(self::URL)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/absensi-mobile/Cuti')
                ->where('unavailableReason', null)->where('balance.available', 12)->where('balance.total', 12)
                ->where('balance.used', 0)->where('balance.pending', 0)->has('approvalPreview', 1)
                ->where('filters.month', '2026-10')->where('employee.nama', 'Nadia Putri')->has('requests.data', 0));
        $cuti = $this->submit();
        $this->assertSame($this->employee->id, $cuti->user_id);
        $this->assertSame($this->pegawai->id, $cuti->pegawai_id);
        $this->assertSame($this->approver->id, $cuti->approvals->sole()->user_id);
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)->where('balance.available', 10)->where('balance.pending', 2)
            ->where('requests.data.0.can_decide', false));
    }

    public function test_revoking_action_permissions_keeps_leave_readable_but_blocks_decisions_and_cancellation(): void
    {
        $cuti = $this->submit();
        $role = Role::findByName('pegawai');
        $role->revokePermissionTo([AccessPermission::MOBILE_CUTI_APPROVE, AccessPermission::MOBILE_CUTI_CANCEL]);

        $this->actingAs($this->employee)->get(self::URL.'/'.$cuti->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('initialRequest.can_cancel', false));
        $this->post(self::URL.'/'.$cuti->id.'/cancel')->assertForbidden();
        $this->actingAs($this->approver)->get(self::URL.'?scope=delegation')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.can_decide', false));
        $this->post(self::URL.'/'.$cuti->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->post('/absensi/mobile/notifikasi/cuti/approve-many', ['request_ids' => [$cuti->id]])->assertForbidden();
        $this->assertSame('pending', $cuti->fresh()->status);
    }

    public function test_balance_reserves_pending_days_and_releases_them_after_rejection(): void
    {
        $cuti = $this->submit();
        $url = self::URL."/{$cuti->id}/decision";
        $this->actingAs($this->approver)->from(self::URL.'?scope=delegation')->post($url, ['decision' => 'rejected'])
            ->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'rejected', 'note' => 'Jadwal perlu disesuaikan'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', 12)->where('balance.pending', 0)->where('requests.data.0.status', 'rejected')
            ->where('requests.data.0.approvals.0.note', 'Jadwal perlu disesuaikan'));
    }

    public function test_delegation_is_limited_to_assigned_requests_and_requires_current_approver(): void
    {
        $cuti = $this->submit();
        $stranger = User::factory()->create();
        $stranger->assignRole('pegawai');
        $this->actingAs($stranger)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->get(self::URL.'?scope=delegation')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0)->where('delegationCount', 0));
        $this->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->employee)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->approver)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->get(self::URL.'?scope=delegation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)->where('requests.data.0.can_decide', true)->where('delegationCount', 1));
        $this->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', 10)->where('balance.used', 2)->where('balance.pending', 0));
    }

    public function test_filters_include_overlapping_dates_and_balance_follows_selected_year(): void
    {
        $this->submit(['tanggal_mulai' => '2026-10-31', 'tanggal_selesai' => '2026-11-02']);
        $this->get(self::URL.'?month=2026-11&status=pending')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)->where('balance.available', 9));
        $this->get(self::URL.'?month=2026-11&status=approved')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->get(self::URL.'?month=2027-01')->assertOk()->assertInertia(fn (Assert $page) => $page->where('balance', null)->has('requests.data', 0));
        $this->get(self::URL.'?month=bad')->assertSessionHasErrors('month');
        $this->get(self::URL.'?scope=all')->assertSessionHasErrors('scope');
        $this->get(self::URL.'?status=waiting')->assertSessionHasErrors('status');
    }

    public function test_submission_reuses_quota_date_and_overlap_validation(): void
    {
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['tanggal_mulai' => '2026-10-18']))->assertSessionHasErrors('tanggal_mulai');
        $this->post(self::URL, $this->payload(['tanggal_selesai' => '2026-11-15']))->assertSessionHasErrors('tanggal_selesai');
        $this->post(self::URL, $this->payload(['tanggal_mulai' => '2026-12-31', 'tanggal_selesai' => '2027-01-02']))->assertSessionHasErrors('tanggal_selesai');
        $this->post(self::URL, $this->payload(['alasan' => '   ']))->assertSessionHasErrors('alasan');
        $this->submit();
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('tanggal_mulai');
        $this->assertDatabaseCount('cuti_requests', 1);
    }

    public function test_unconfigured_accounts_render_explanation_and_cannot_submit(): void
    {
        $this->pegawai->delete();
        $this->actingAs($this->employee)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('balance', null)->where('employee', null)->where('leaveOptions', [])
            ->where('unavailableReason', 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.'));
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('cuti');
    }

    public function test_owner_can_cancel_pending_leave_restore_balance_and_reuse_dates(): void
    {
        $cuti = $this->submit();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.can_cancel', true)->where('balance.available', 10));

        $this->from(self::URL)->post(self::URL."/{$cuti->id}/cancel")
            ->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(self::URL);
        $cuti->refresh();
        $this->assertSame('cancelled', $cuti->status);
        $this->assertNull($cuti->current_level);
        $this->assertNotNull($cuti->decided_at);
        $this->assertSame('skipped', $cuti->approvals()->sole()->status);
        $this->get(self::URL.'?status=cancelled')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)->where('requests.data.0.status', 'cancelled')
            ->where('requests.data.0.can_cancel', false)->where('requests.data.0.can_decide', false)
            ->where('balance.available', 12)->where('balance.pending', 0));

        $this->actingAs($this->approver)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page
            ->where('delegationCount', 0)->where('requests.data.0.can_decide', false)->where('requests.data.0.can_cancel', false));
        $this->from(self::URL)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])
            ->assertSessionHasErrors('decision');
        $this->submit();
        $this->assertDatabaseCount('cuti_requests', 2);
    }

    public function test_approver_other_employee_and_super_admin_cannot_cancel_someone_elses_leave(): void
    {
        $cuti = $this->submit();
        $stranger = User::factory()->create();
        $stranger->assignRole('pegawai');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        foreach ([$this->approver, $stranger, $admin] as $actor) {
            $this->actingAs($actor)->post(self::URL."/{$cuti->id}/cancel")->assertForbidden();
        }
        $this->assertSame('pending', $cuti->fresh()->status);
        $this->assertSame('pending', $cuti->approvals()->sole()->status);
    }

    public function test_approved_rejected_and_already_cancelled_leave_cannot_be_cancelled(): void
    {
        $cuti = $this->submit();
        $this->actingAs($this->approver)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->from(self::URL)->post(self::URL."/{$cuti->id}/cancel")->assertSessionHasErrors('cancel');
        $this->assertSame('approved', $cuti->fresh()->status);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', 10)->where('balance.used', 2)->where('requests.data.0.can_cancel', false));

        $rejected = $this->submit(['tanggal_mulai' => '2026-10-29', 'tanggal_selesai' => '2026-10-30']);
        $this->actingAs($this->approver)->post(self::URL."/{$rejected->id}/decision", ['decision' => 'rejected', 'note' => 'Jadwal perlu disesuaikan'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->from(self::URL)->post(self::URL."/{$rejected->id}/cancel")->assertSessionHasErrors('cancel');
        $this->assertSame('rejected', $rejected->fresh()->status);

        $cancelled = $this->submit(['tanggal_mulai' => '2026-11-02', 'tanggal_selesai' => '2026-11-03']);
        $this->from(self::URL)->post(self::URL."/{$cancelled->id}/cancel")->assertSessionHasNoErrors();
        $this->post(self::URL."/{$cancelled->id}/cancel")->assertSessionHasErrors('cancel');
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_cancellation_preserves_completed_approval_and_stops_remaining_steps(): void
    {
        $second = User::factory()->create();
        $second->assignRole('pegawai');
        ApprovalLine::query()->sole()->steps()->create(['user_id' => $second->id, 'level' => 2]);
        $cuti = $this->submit();
        $this->actingAs($this->approver)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved', 'note' => 'Disetujui kepala unit'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.can_cancel', true)->where('requests.data.0.current_level', 2));
        $this->from(self::URL)->post(self::URL."/{$cuti->id}/cancel")->assertSessionHasNoErrors();
        $steps = $cuti->approvals()->get();
        $this->assertSame(['approved', 'skipped'], $steps->pluck('status')->all());
        $this->assertSame('Disetujui kepala unit', $steps[0]->note);
        $this->assertNotNull($steps[0]->decided_at);
        $this->actingAs($second)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page->where('delegationCount', 0));
        $this->from(self::URL)->post(self::URL."/{$cuti->id}/decision", ['decision' => 'approved'])->assertSessionHasErrors('decision');
    }

    public function test_cancellation_requires_authentication_mobile_access_and_verified_email(): void
    {
        $cuti = $this->submit();
        auth()->forgetGuards();
        $this->post(self::URL."/{$cuti->id}/cancel")->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->post(self::URL."/{$cuti->id}/cancel")->assertForbidden();
        $this->employee->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->employee)->post(self::URL."/{$cuti->id}/cancel")->assertRedirect(route('verification.notice'));
        $this->assertSame('pending', $cuti->fresh()->status);
    }

    public function test_mobile_access_and_verification_are_required(): void
    {
        $this->get(self::URL)->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get(self::URL)->assertForbidden();
        $this->post(self::URL, $this->payload())->assertForbidden();
        $user->assignRole('pegawai');
        $user->forceFill(['email_verified_at' => null])->save();
        $this->get(self::URL)->assertRedirect(route('verification.notice'));
        $this->post(self::URL, $this->payload())->assertRedirect(route('verification.notice'));
    }
}
