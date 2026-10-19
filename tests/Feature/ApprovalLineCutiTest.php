<?php

namespace Tests\Feature;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AdminWeb\Setting\User\UserService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalLineCutiTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = '/absensi/admin-web/approval-line';

    private const CUTI = '/absensi/admin-web/cuti';

    private const NOTIFICATIONS = '/absensi/admin-web/notifikasi';

    private User $admin;

    private User $applicant;

    private Jabatan $jabatan;

    private MasterCuti $masterCuti;

    /** @var list<User> */
    private array $approvers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->startOfDay());
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->applicant = $this->webUser();
        $unit = UnitKerja::query()->create(['kode' => 'SIRS', 'nama' => 'SIRS', 'aktif' => true]);
        $this->jabatan = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'STF', 'nama' => 'Staff SIRS', 'aktif' => true]);
        $pegawai = Pegawai::query()->create(['nama' => 'Staff Pemohon', 'jabatan_id' => $this->jabatan->id, 'unit_kerja_id' => $unit->id]);
        $pegawai->forceFill(['user_id' => $this->applicant->id])->save();
        $this->masterCuti = MasterCuti::query()->create(['kode' => 'KELUARGA', 'nama' => 'Cuti Keluarga', 'durasi_hari' => 30, 'aktif' => true]);
        PegawaiCuti::query()->create(['master_cuti_id' => $this->masterCuti->id, 'pegawai_id' => $pegawai->id, 'tahun' => 0, 'jumlah_hari' => 30]);
        $this->approvers = [$this->webUser(), $this->webUser(), $this->webUser()];
    }

    private function webUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::ADMIN_ACCESS);

        return $user;
    }

    private function configure(int $levels = 3): ApprovalLine
    {
        $this->actingAs($this->admin)->post(self::LINES, [
            'jabatan_ids' => [$this->jabatan->id],
            'approver_ids' => array_map(fn (User $user) => $user->id, array_slice($this->approvers, 0, $levels)),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return ApprovalLine::query()->sole();
    }

    private function payload(): array
    {
        return ['master_cuti_id' => $this->masterCuti->id, 'tanggal_mulai' => '2026-10-12', 'tanggal_selesai' => '2026-10-14', 'alasan' => 'Keperluan keluarga'];
    }

    private function submit(): CutiRequest
    {
        $this->actingAs($this->applicant)->post(self::CUTI, $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(self::CUTI);

        return CutiRequest::query()->latest('id')->firstOrFail();
    }

    private function decisionUrl(CutiRequest $cuti): string
    {
        return self::CUTI."/{$cuti->id}/decision";
    }

    public function test_configuration_and_cuti_pages_render_with_position_and_approver_options(): void
    {
        $this->configure();
        $this->actingAs($this->admin)->get(self::LINES)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/approval-line/Index')
                ->has('lines.data', 1)->has('lines.data.0.steps', 3)->where('lines.data.0.jabatans.0.nama', 'Staff SIRS')
                ->where('can.create', true)->has('userOptions', 5));

        $this->actingAs($this->applicant)->get(self::CUTI)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/cuti/Index')
                ->where('unavailableReason', null)->has('approvalPreview', 3)->where('canViewAll', false));
    }

    public function test_approval_line_management_requires_its_own_permissions(): void
    {
        $line = $this->configure();
        $this->actingAs($this->applicant)->get(self::LINES)->assertForbidden();
        $this->post(self::LINES, [])->assertForbidden();
        $this->put(self::LINES."/{$line->id}", [])->assertForbidden();
        $this->delete(self::LINES."/{$line->id}")->assertForbidden();

        $this->applicant->givePermissionTo(AccessPermission::APPROVAL_LINE_VIEW);
        $this->get(self::LINES)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.create', false)->where('can.update', false)->where('can.delete', false));
    }

    public function test_configuration_requires_one_to_three_distinct_existing_users(): void
    {
        $this->actingAs($this->admin);
        $this->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => []])->assertSessionHasErrors('approver_ids');
        $this->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [
            $this->approvers[0]->id, $this->approvers[1]->id, $this->approvers[2]->id, $this->admin->id,
        ]])->assertSessionHasErrors('approver_ids');
        $this->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[0]->id, $this->approvers[0]->id]])->assertSessionHasErrors('approver_ids.0');

        $this->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [999999]])->assertSessionHasErrors('approver_ids.0');
        $this->assertDatabaseCount('approval_lines', 0);
    }

    public function test_all_users_can_be_selected_without_role_portal_or_verification_restrictions(): void
    {
        $ordinary = User::factory()->create();
        $mobileOnly = User::factory()->unverified()->create();
        $mobileOnly->assignRole('pegawai');
        $this->actingAs($this->admin)->get(self::LINES)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('userOptions', 7)
                ->where('userOptions', fn ($users) => collect($users)->pluck('id')->contains($ordinary->id)
                    && collect($users)->pluck('id')->contains($mobileOnly->id)));
        $this->post(self::LINES, [
            'jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$ordinary->id, $mobileOnly->id],
        ])->assertSessionHasNoErrors();
        $cuti = $this->submit();
        $this->assertSame([$ordinary->id, $mobileOnly->id], $cuti->approvals->pluck('user_id')->all());
        $this->actingAs($ordinary)->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($mobileOnly)->get(self::CUTI)->assertRedirect(route('verification.notice'));
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertRedirect(route('verification.notice'));
        $mobileOnly->forceFill(['email_verified_at' => now()])->save();
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $cuti->fresh()->status);
    }

    private function otherPosition(string $code = 'LKS'): Jabatan
    {
        return Jabatan::query()->create([
            'unit_kerja_id' => $this->jabatan->unit_kerja_id, 'kode' => $code,
            'nama' => "Pelaksana {$code}", 'aktif' => true,
        ]);
    }

    public function test_multiple_positions_share_one_line_and_each_applicant_uses_its_approvers(): void
    {
        $other = $this->otherPosition();
        $otherUnit = UnitKerja::query()->create(['kode' => 'TU', 'nama' => 'Tata Usaha', 'aktif' => true]);
        $other->update(['unit_kerja_id' => $otherUnit->id]);
        $this->actingAs($this->admin)->post(self::LINES, [
            'jabatan_ids' => [$this->jabatan->id, $other->id],
            'approver_ids' => [$this->approvers[0]->id, $this->approvers[1]->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('approval_lines', 1);
        $this->assertDatabaseCount('approval_line_jabatan', 2);
        $this->assertDatabaseCount('approval_line_steps', 2);
        $this->get(self::LINES.'?search=Pelaksana')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lines.data', 1)->has('lines.data.0.jabatans', 2)
                ->where('overview.configured', 2));

        $first = $this->submit();
        $secondApplicant = $this->webUser();
        $employee = Pegawai::query()->create([
            'nama' => 'Pemohon kedua', 'jabatan_id' => $other->id, 'unit_kerja_id' => $other->unit_kerja_id,
        ]);
        $employee->forceFill(['user_id' => $secondApplicant->id])->save();
        PegawaiCuti::query()->create(['master_cuti_id' => $this->masterCuti->id, 'pegawai_id' => $employee->id, 'tahun' => 0, 'jumlah_hari' => 30]);
        $this->actingAs($secondApplicant)->get(self::CUTI)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('unavailableReason', null)->has('approvalPreview', 2));
        $this->post(self::CUTI, $this->payload())->assertSessionHasNoErrors();
        $second = CutiRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($other->id, $second->jabatan_id);
        $this->assertSame($first->approvals->pluck('user_id')->all(), $second->approvals->pluck('user_id')->all());
    }

    public function test_editing_shared_positions_preserves_pending_requests_and_releases_removed_positions(): void
    {
        $line = $this->configure(1);
        $cuti = $this->submit();
        $other = $this->otherPosition();
        $third = $this->otherPosition('KPL');
        $this->actingAs($this->admin)->put(self::LINES."/{$line->id}", [
            'jabatan_ids' => [$this->jabatan->id, $other->id], 'approver_ids' => [$this->approvers[1]->id],
        ])->assertSessionHasNoErrors();
        $this->put(self::LINES."/{$line->id}", [
            'jabatan_ids' => [$other->id, $third->id], 'approver_ids' => [$this->approvers[2]->id],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$other->id, $third->id], $line->fresh()->jabatans->modelKeys());
        $this->assertSame([$this->approvers[0]->id], $cuti->approvals->pluck('user_id')->all());
        $this->post(self::LINES, [
            'jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[1]->id],
        ])->assertSessionHasNoErrors();
        $this->delete(self::LINES."/{$line->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('approval_line_jabatan', ['approval_line_id' => $line->id]);
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $cuti->fresh()->status);
    }

    public function test_invalid_position_lists_and_conflicts_do_not_partially_save_a_line(): void
    {
        $this->configure(1);
        $other = $this->otherPosition();
        $inactive = $this->otherPosition('OFF');
        $inactive->update(['aktif' => false]);
        $this->actingAs($this->admin);
        foreach ([[], [$other->id, $other->id], [$other->id, 999999], [$other->id, $inactive->id], [$other->id, $this->jabatan->id]] as $positions) {
            $this->post(self::LINES, ['jabatan_ids' => $positions, 'approver_ids' => [$this->approvers[1]->id]])->assertSessionHasErrors();
            $this->assertDatabaseCount('approval_lines', 1);
            $this->assertDatabaseCount('approval_line_jabatan', 1);
            $this->assertDatabaseMissing('approval_line_jabatan', ['jabatan_id' => $other->id]);
        }
    }

    public function test_only_one_cuti_line_can_exist_for_each_position(): void
    {
        $this->configure(1);
        $this->actingAs($this->admin)->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[1]->id]])->assertSessionHasErrors('jabatan_ids.0');
        $this->assertDatabaseCount('approval_lines', 1);
    }

    public function test_three_levels_are_processed_sequentially_and_only_last_approval_finishes_request(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $this->assertSame(['pending', 'waiting', 'waiting'], $cuti->approvals->pluck('status')->all());

        $this->actingAs($this->approvers[1])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->admin)->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->applicant)->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();

        foreach ($this->approvers as $index => $approver) {
            $this->actingAs($approver)->post($this->decisionUrl($cuti), ['decision' => 'approved', 'note' => 'Sesuai'])->assertSessionHasNoErrors();
            $cuti->refresh();
            $this->assertSame($index === 2 ? 'approved' : 'pending', $cuti->status);
            $this->assertSame($index === 2 ? null : $index + 2, $cuti->current_level);
            if ($index === 0) {
                $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();
            }
        }

        $this->assertNotNull($cuti->decided_at);
        $this->assertSame(['approved', 'approved', 'approved'], $cuti->approvals->pluck('status')->all());
        $this->post($this->decisionUrl($cuti), ['decision' => 'rejected', 'note' => 'Ubah keputusan'])->assertSessionHasErrors('decision');
        $this->assertSame('approved', $cuti->fresh()->status);
    }

    public function test_one_and_two_level_lines_finish_at_their_last_configured_approver(): void
    {
        $line = $this->configure(1);
        $first = $this->submit();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($first), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $first->fresh()->status);

        $this->actingAs($this->admin)->put(self::LINES."/{$line->id}", ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[0]->id, $this->approvers[1]->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->applicant)->post(self::CUTI, array_replace($this->payload(), ['tanggal_mulai' => '2026-10-20', 'tanggal_selesai' => '2026-10-21']))->assertSessionHasNoErrors();
        $second = CutiRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($second), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $second->fresh()->status);
        $this->actingAs($this->approvers[1])->post($this->decisionUrl($second), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $second->fresh()->status);
    }

    public function test_rejection_requires_a_reason_and_stops_all_remaining_levels(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->approvers[1])->post($this->decisionUrl($cuti), ['decision' => 'rejected', 'note' => '   '])->assertSessionHasErrors('note');
        $this->post($this->decisionUrl($cuti), ['decision' => 'rejected', 'note' => 'Jadwal pelayanan belum dapat ditinggalkan'])->assertSessionHasNoErrors();
        $cuti->refresh();
        $this->assertSame('rejected', $cuti->status);
        $this->assertNull($cuti->current_level);
        $this->assertNotNull($cuti->decided_at);
        $this->assertSame(['approved', 'rejected', 'skipped'], $cuti->approvals->pluck('status')->all());
        $this->actingAs($this->approvers[2])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasErrors('decision');
    }

    public function test_pending_requests_keep_their_snapshot_after_line_edit_and_deletion(): void
    {
        $line = $this->configure();
        $cuti = $this->submit();
        $replacement = $this->webUser();
        $this->actingAs($this->admin)->put(self::LINES."/{$line->id}", ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$replacement->id]])->assertSessionHasNoErrors();
        $this->delete(self::LINES."/{$line->id}")->assertSessionHasNoErrors();
        $this->assertSame(array_map(fn (User $user) => $user->id, $this->approvers), $cuti->approvals->pluck('user_id')->all());
        $this->actingAs($replacement)->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();
        foreach ($this->approvers as $approver) {
            $this->actingAs($approver)->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        }
        $this->assertSame('approved', $cuti->fresh()->status);
        $this->assertDatabaseCount('approval_lines', 0);
    }

    public function test_inbox_receives_a_request_only_when_the_previous_approver_has_approved(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $this->actingAs($this->approvers[1])->get(self::CUTI.'?scope=inbox')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0)->where('overview.inbox', 0));
        $this->actingAs($this->approvers[0])->get(self::CUTI.'?scope=inbox')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.can_decide', true));
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->get(self::CUTI.'?scope=history')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
        $this->actingAs($this->approvers[1])->get(self::CUTI.'?scope=inbox')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.can_decide', true));
        $this->actingAs($this->approvers[2])->get(self::CUTI.'?scope=inbox')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
    }

    public function test_approval_notifications_follow_the_current_approver_and_support_decisions(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $this->actingAs($this->admin)->get(self::NOTIFICATIONS.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/notifikasi/Index')->has('requests.data', 0));
        $this->actingAs($this->approvers[1])->get(self::NOTIFICATIONS.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('category', 'butuh-approval')->where('overview.inbox', 0)->has('requests.data', 0));
        $this->actingAs($this->approvers[0])->get(self::NOTIFICATIONS.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('overview.inbox', 1)->has('requests.data', 1)
                ->where('requests.data.0.id', $cuti->id)->where('requests.data.0.can_decide', true)->has('requests.data.0.approvals', 3));
        $this->from(self::NOTIFICATIONS.'/butuh-approval')->post($this->decisionUrl($cuti), ['decision' => 'approved'])
            ->assertSessionHasNoErrors()->assertRedirect(self::NOTIFICATIONS.'/butuh-approval');
        $this->get(self::NOTIFICATIONS.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('overview.inbox', 0)->has('requests.data', 0));
        $this->actingAs($this->approvers[1])->get(self::NOTIFICATIONS.'/butuh-approval')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('overview.inbox', 1)->where('requests.data.0.can_decide', true));
    }

    public function test_approved_notifications_only_include_completed_requests_visible_to_the_user(): void
    {
        $this->configure();
        $cuti = $this->submit();
        foreach ($this->approvers as $approver) {
            $this->actingAs($approver)->get(self::NOTIFICATIONS.'/disetujui')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('overview.approved', 0)->has('requests.data', 0));
            $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        }
        foreach ([$this->applicant, ...$this->approvers, $this->admin] as $user) {
            $this->actingAs($user)->get(self::NOTIFICATIONS.'/disetujui')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('category', 'disetujui')->where('overview.approved', 1)
                    ->has('requests.data', 1)->where('requests.data.0.id', $cuti->id)->where('requests.data.0.status', 'approved')
                    ->where('requests.data.0.can_decide', false));
        }
        $this->actingAs($this->webUser())->get(self::NOTIFICATIONS.'/disetujui?scope=all&status=pending')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('overview.approved', 0)->has('requests.data', 0));
    }

    public function test_rejected_requests_are_excluded_from_both_notification_categories(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $this->actingAs($this->approvers[0])->from(self::NOTIFICATIONS.'/butuh-approval')
            ->post($this->decisionUrl($cuti), ['decision' => 'rejected', 'note' => 'Jadwal penuh'])
            ->assertSessionHasNoErrors()->assertRedirect(self::NOTIFICATIONS.'/butuh-approval');
        foreach (['butuh-approval', 'disetujui'] as $category) {
            $this->get(self::NOTIFICATIONS.'/'.$category)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0)->where('overview.inbox', 0)->where('overview.approved', 0));
        }
    }

    public function test_notification_search_keeps_counts_and_pagination_scoped_to_the_user(): void
    {
        $this->configure(1);
        $cuti = $this->submit();
        for ($index = 0; $index < 11; $index++) {
            $copy = $cuti->replicate();
            $copy->nama_pegawai = 'Pegawai Lain';
            $copy->save();
            $copy->approvals()->create($cuti->approvals->first()->only(['level', 'user_id', 'approver_name', 'status']));
        }
        $this->actingAs($this->approvers[0])->get(self::NOTIFICATIONS.'/butuh-approval?search=Staff%20Pemohon')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $cuti->id)
                ->where('requests.total', 1)->where('filters.search', 'Staff Pemohon')->where('overview.inbox', 12));
        $this->get(self::NOTIFICATIONS.'/butuh-approval?search=Pegawai&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.total', 11)
                ->where('requests.current_page', 2)->where('filters.search', 'Pegawai')->where('overview.inbox', 12));
        $this->getJson(self::NOTIFICATIONS.'/butuh-approval?search='.str_repeat('a', 151))->assertUnprocessable();
    }

    public function test_notification_routes_require_verified_admin_or_assigned_approver_access(): void
    {
        $this->get(self::NOTIFICATIONS.'/butuh-approval')->assertRedirect(route('absensi.admin-web.login'));
        $ordinary = User::factory()->create();
        foreach (['butuh-approval', 'disetujui'] as $category) {
            $this->actingAs($ordinary)->get(self::NOTIFICATIONS.'/'.$category)->assertForbidden();
        }
        $this->approvers[0]->revokePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->configure(1);
        $this->actingAs($this->approvers[0])->get(self::NOTIFICATIONS)->assertRedirect(self::NOTIFICATIONS.'/butuh-approval');
        $this->get(self::NOTIFICATIONS.'/disetujui')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal.can_access_admin', false)->where('portal.can_access_cuti', true));
        $this->approvers[0]->forceFill(['email_verified_at' => null])->save();
        $this->get(self::NOTIFICATIONS.'/butuh-approval')->assertRedirect(route('verification.notice'));
    }

    public function test_other_users_cannot_browse_all_requests(): void
    {
        $this->configure();
        $this->submit();
        $other = $this->webUser();
        $this->actingAs($other)->get(self::CUTI)->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));
        $this->getJson(self::CUTI.'?scope=all')->assertUnprocessable();
        $this->actingAs($this->admin)->get(self::CUTI.'?scope=all')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
    }

    public function test_submission_requires_a_configured_line_and_employee_position(): void
    {
        $this->actingAs($this->applicant)->post(self::CUTI, $this->payload())->assertSessionHasErrors('cuti');
        $this->configure();
        $unlinked = $this->webUser();
        $this->actingAs($unlinked)->post(self::CUTI, $this->payload())->assertSessionHasErrors('cuti');
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    public function test_a_user_cannot_submit_a_request_that_they_would_approve_themselves(): void
    {
        $this->actingAs($this->admin)->post(self::LINES, ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->applicant->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->applicant)->post(self::CUTI, $this->payload())->assertSessionHasErrors('cuti');
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    public function test_invalid_dates_and_overlapping_active_requests_are_rejected(): void
    {
        $this->configure();
        $this->actingAs($this->applicant)->post(self::CUTI, array_replace($this->payload(), ['tanggal_mulai' => '2026-10-07']))->assertSessionHasErrors('tanggal_mulai');
        $this->post(self::CUTI, array_replace($this->payload(), ['tanggal_selesai' => '2026-10-11']))->assertSessionHasErrors('tanggal_selesai');
        $this->submit();
        $this->post(self::CUTI, $this->payload())->assertSessionHasErrors('tanggal_mulai');
        $this->assertDatabaseCount('cuti_requests', 1);
    }

    public function test_single_day_requests_and_shared_boundary_dates_cannot_overlap(): void
    {
        $this->configure(1);
        $singleDay = array_replace($this->payload(), ['tanggal_selesai' => '2026-10-12']);
        $this->actingAs($this->applicant)->post(self::CUTI, $singleDay)->assertSessionHasNoErrors();
        $this->post(self::CUTI, $singleDay)->assertSessionHasErrors('tanggal_mulai');
        $this->post(self::CUTI, array_replace($singleDay, ['tanggal_mulai' => '2026-10-11']))->assertSessionHasErrors('tanggal_mulai');
        $cuti = CutiRequest::query()->sole();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->applicant)->post(self::CUTI, $singleDay)->assertSessionHasErrors('tanggal_mulai');
        $this->post(self::CUTI, array_replace($singleDay, ['tanggal_mulai' => '2026-10-13', 'tanggal_selesai' => '2026-10-13']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cuti_requests', 2);
    }

    public function test_approvers_may_receive_web_access_through_a_role(): void
    {
        $role = Role::query()->create(['name' => 'kepala-unit', 'guard_name' => 'web']);
        $role->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->approvers[0]->revokePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->approvers[0]->assignRole($role);
        $this->configure(1);
        $cuti = $this->submit();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $cuti->fresh()->status);
    }

    public function test_inactive_units_cannot_receive_new_lines_or_submissions(): void
    {
        $line = $this->configure(1);
        $this->jabatan->unitKerja->update(['aktif' => false]);
        $this->actingAs($this->applicant)->post(self::CUTI, $this->payload())->assertSessionHasErrors('cuti');
        $this->actingAs($this->admin)->put(self::LINES."/{$line->id}", ['jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[1]->id]])->assertSessionHasErrors('jabatan_ids.0');
        $this->assertDatabaseHas('approval_line_steps', ['approval_line_id' => $line->id, 'user_id' => $this->approvers[0]->id]);
    }

    public function test_approvers_do_not_need_admin_access_for_submission_or_decisions(): void
    {
        $this->configure();
        $this->approvers[1]->revokePermissionTo(AccessPermission::ADMIN_ACCESS);
        $cuti = $this->submit();
        $this->actingAs($this->approvers[0])->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->approvers[1])->get(self::CUTI)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.scope', 'inbox')->where('canSubmit', false)
                ->where('portal.can_access_admin', false)->where('portal.can_access_cuti', true)->has('requests.data', 1));
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->get('/absensi/admin-web/dashboard')->assertForbidden();
        $this->get(self::LINES)->assertForbidden();
        $this->post(self::CUTI, $this->payload())->assertForbidden();
    }

    public function test_assigned_users_can_login_to_their_tasks_and_keep_access_to_snapshot_history(): void
    {
        $ordinary = User::factory()->create();
        $this->actingAs($this->admin)->post(self::LINES, [
            'jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$ordinary->id],
        ])->assertSessionHasNoErrors();
        $line = ApprovalLine::query()->sole();
        $cuti = $this->submit();
        $this->actingAs($this->admin)->delete(self::LINES."/{$line->id}")->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/absensi/admin-web/login', ['email' => $ordinary->email, 'password' => 'password'])->assertRedirect(self::NOTIFICATIONS.'/butuh-approval');
        $this->get(self::CUTI)->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->get(self::CUTI.'?scope=history')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
    }

    public function test_users_with_pending_approval_assignments_cannot_be_deleted_even_after_configuration_is_removed(): void
    {
        $line = $this->configure();
        $this->submit();
        $this->actingAs($this->admin)->delete(self::LINES."/{$line->id}")->assertSessionHasNoErrors();
        $this->expectException(ValidationException::class);
        app(UserService::class)->delete($this->approvers[1], $this->admin);
    }

    public function test_web_portal_permission_is_required_for_cuti_routes(): void
    {
        $this->configure();
        $cuti = $this->submit();
        $mobileOnly = User::factory()->create();
        $mobileOnly->assignRole('pegawai');
        $this->actingAs($mobileOnly)->get(self::CUTI)->assertForbidden();
        $this->post(self::CUTI, $this->payload())->assertForbidden();
        $this->post($this->decisionUrl($cuti), ['decision' => 'approved'])->assertForbidden();
    }
}
