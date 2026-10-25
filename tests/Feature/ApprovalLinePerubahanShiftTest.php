<?php

namespace Tests\Feature;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\CutiAccess;
use App\Support\PengajuanAccess;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApprovalLinePerubahanShiftTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = '/absensi/admin-web/approval-line';

    private User $admin;

    private Jabatan $jabatan;

    private Jabatan $other;

    /** @var list<User> */
    private array $approvers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'Instalasi Gawat Darurat', 'aktif' => true]);
        $this->jabatan = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->other = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'KPL', 'nama' => 'Kepala IGD', 'aktif' => true]);
        $this->approvers = User::factory()->count(3)->create()->all();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'type' => ApprovalLine::TYPE_PERUBAHAN_SHIFT,
            'jabatan_ids' => [$this->jabatan->id],
            'approver_ids' => [$this->approvers[0]->id],
        ], $overrides);
    }

    private function configure(array $overrides = []): ApprovalLine
    {
        $this->actingAs($this->admin)->post(self::LINES, $this->payload($overrides))->assertSessionHasNoErrors();

        return ApprovalLine::query()->latest('id')->firstOrFail();
    }

    public function test_shift_change_supports_shared_positions_and_three_approvers_alongside_other_categories(): void
    {
        $this->configure(['type' => ApprovalLine::TYPE_CUTI]);
        $this->configure(['type' => ApprovalLine::TYPE_LEMBUR]);
        $ids = array_map(fn (User $user) => $user->id, $this->approvers);
        $this->actingAs($this->admin)->from(self::LINES.'?type=perubahan-shift')->post(self::LINES, $this->payload([
            'jabatan_ids' => [$this->jabatan->id, $this->other->id], 'approver_ids' => $ids,
        ]))->assertSessionHasNoErrors()->assertRedirect(self::LINES.'?type=perubahan-shift')
            ->assertSessionHas('success', 'Approval line perubahan shift berhasil ditambahkan.');

        $line = ApprovalLine::query()->where('type', ApprovalLine::TYPE_PERUBAHAN_SHIFT)->sole();
        $this->assertDatabaseCount('approval_lines', 3);
        $this->assertDatabaseCount('approval_line_jabatan', 4);
        $this->assertSame($ids, $line->steps->pluck('user_id')->all());
        $this->assertSame([1, 2, 3], $line->steps->pluck('level')->all());
        foreach ([$this->jabatan, $this->other] as $position) {
            $this->assertDatabaseHas('approval_line_jabatan', [
                'approval_line_id' => $line->id, 'jabatan_id' => $position->id, 'type' => 'perubahan-shift',
            ]);
        }
    }

    public function test_shift_change_category_scopes_search_counts_and_available_positions(): void
    {
        $cuti = $this->configure(['type' => ApprovalLine::TYPE_CUTI]);
        $this->get(self::LINES.'?type=perubahan-shift')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'perubahan-shift')->has('lines.data', 0)->where('overview.configured', 0)
            ->where('jabatanOptions.1.configured', false)
            ->where('typeOptions.2', ['value' => 'perubahan-shift', 'label' => 'Perubahan Shift']));
        $line = $this->configure(['jabatan_ids' => [$this->jabatan->id, $this->other->id], 'approver_ids' => [$this->approvers[2]->id]]);
        foreach (['Perawat', 'Instalasi Gawat Darurat', $this->approvers[2]->name] as $search) {
            $this->get(self::LINES.'?type=perubahan-shift&search='.urlencode($search))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('lines.data', 1)->where('lines.data.0.id', $line->id)
                    ->where('lines.data.0.type', 'perubahan-shift')->where('overview.configured', 2)
                    ->where('jabatanOptions.0.configured', true)->where('jabatanOptions.1.configured', true));
        }
        $this->get(self::LINES.'?type=perubahan-shift&search=TidakAda')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lines.data', 0)->where('overview.configured', 2));
        $this->get(self::LINES)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'cuti')->has('lines.data', 1)->where('lines.data.0.id', $cuti->id));
    }

    public function test_updating_and_deleting_shift_change_releases_positions_and_preserves_other_categories(): void
    {
        $cuti = $this->configure(['type' => ApprovalLine::TYPE_CUTI]);
        $lembur = $this->configure(['type' => ApprovalLine::TYPE_LEMBUR]);
        $line = $this->configure();
        $this->put(self::LINES."/{$line->id}", [
            'jabatan_ids' => [$this->other->id], 'approver_ids' => [$this->approvers[2]->id, $this->approvers[1]->id],
        ])->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame('perubahan-shift', $line->type);
        $this->assertSame([$this->other->id], $line->jabatans->modelKeys());
        $this->assertSame([$this->approvers[2]->id, $this->approvers[1]->id], $line->steps->pluck('user_id')->all());
        $replacement = $this->configure();
        $this->put(self::LINES."/{$replacement->id}", $this->payload(['jabatan_ids' => [$this->other->id]]))
            ->assertSessionHasErrors('jabatan_ids.0');
        $this->delete(self::LINES."/{$line->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('approval_lines', ['id' => $line->id]);
        $this->assertDatabaseMissing('approval_line_steps', ['approval_line_id' => $line->id]);
        $this->assertDatabaseMissing('approval_line_jabatan', ['approval_line_id' => $line->id]);
        foreach ([$cuti, $lembur, $replacement] as $preserved) {
            $this->assertSame([$this->jabatan->id], $preserved->fresh()->jabatans->modelKeys());
            $this->assertSame([$this->approvers[0]->id], $preserved->fresh()->steps->pluck('user_id')->all());
        }
    }

    public function test_shift_change_requires_active_positions_and_one_to_three_distinct_users(): void
    {
        $this->actingAs($this->admin);
        foreach ([[], [999999], [$this->approvers[0]->id, $this->approvers[0]->id], array_merge(array_column($this->approvers, 'id'), [$this->admin->id])] as $ids) {
            $this->post(self::LINES, $this->payload(['approver_ids' => $ids]))->assertSessionHasErrors();
        }
        foreach ([[], [999999], [$this->jabatan->id, $this->jabatan->id]] as $ids) {
            $this->post(self::LINES, $this->payload(['jabatan_ids' => $ids]))->assertSessionHasErrors();
        }
        $this->jabatan->update(['aktif' => false]);
        $this->post(self::LINES, $this->payload())->assertSessionHasErrors('jabatan_ids.0');
        $this->other->unitKerja->update(['aktif' => false]);
        $this->post(self::LINES, $this->payload(['jabatan_ids' => [$this->other->id]]))->assertSessionHasErrors('jabatan_ids.0');
        $this->assertDatabaseCount('approval_lines', 0);
        $this->assertDatabaseCount('approval_line_jabatan', 0);
        $this->assertDatabaseCount('approval_line_steps', 0);
    }

    public function test_shift_change_uses_existing_management_permissions_and_does_not_grant_other_approval_access(): void
    {
        $line = $this->configure();
        $approver = $this->approvers[0];
        $this->assertFalse(CutiAccess::allows($approver));
        $this->assertTrue(PengajuanAccess::allows($approver));
        $approver->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->actingAs($approver)->get(self::LINES.'?type=perubahan-shift')->assertForbidden();
        $this->post(self::LINES, $this->payload())->assertForbidden();
        $this->put(self::LINES."/{$line->id}", $this->payload())->assertForbidden();
        $this->delete(self::LINES."/{$line->id}")->assertForbidden();
        $approver->givePermissionTo(AccessPermission::APPROVAL_LINE_VIEW);
        $this->get(self::LINES.'?type=perubahan-shift')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)->where('can.update', false)->where('can.delete', false));
    }
}
