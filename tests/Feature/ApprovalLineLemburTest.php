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
use App\Services\Absensi\AdminWeb\ApprovalLine\ApprovalLineService;
use App\Support\AccessPermission;
use App\Support\CutiAccess;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApprovalLineLemburTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = '/absensi/admin-web/approval-line';

    private User $admin;

    private Jabatan $jabatan;

    private MasterCuti $masterCuti;

    /** @var list<User> */
    private array $approvers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->masterCuti = MasterCuti::query()->create(['kode' => 'KELUARGA', 'nama' => 'Cuti Keluarga', 'durasi_hari' => 30, 'aktif' => true]);
        $unit = UnitKerja::query()->create(['kode' => 'SIRS', 'nama' => 'SIRS', 'aktif' => true]);
        $this->jabatan = Jabatan::query()->create([
            'unit_kerja_id' => $unit->id, 'kode' => 'STF', 'nama' => 'Staff SIRS', 'aktif' => true,
        ]);
        $this->approvers = User::factory()->count(3)->create()->all();
    }

    private function payload(string $type = ApprovalLine::TYPE_LEMBUR): array
    {
        return ['type' => $type, 'jabatan_ids' => [$this->jabatan->id], 'approver_ids' => [$this->approvers[0]->id]];
    }

    private function configure(string $type = ApprovalLine::TYPE_LEMBUR, array $overrides = []): ApprovalLine
    {
        $label = mb_strtolower(ApprovalLine::typeLabels()[$type]);
        $this->actingAs($this->admin)->from(self::LINES.'?type='.$type)
            ->post(self::LINES, array_replace($this->payload($type), $overrides))
            ->assertSessionHasNoErrors()->assertRedirect(self::LINES.'?type='.$type)
            ->assertSessionHas('success', "Approval line {$label} berhasil ditambahkan.");

        return ApprovalLine::query()->where('type', $type)->latest('id')->firstOrFail();
    }

    private function otherPosition(): Jabatan
    {
        return Jabatan::query()->create([
            'unit_kerja_id' => $this->jabatan->unit_kerja_id, 'kode' => 'KPL', 'nama' => 'Kepala SIRS', 'aktif' => true,
        ]);
    }

    public function test_a_position_can_have_separate_cuti_and_shared_lembur_lines(): void
    {
        $cuti = $this->configure(ApprovalLine::TYPE_CUTI);
        $other = $this->otherPosition();
        $lembur = $this->configure(overrides: [
            'jabatan_ids' => [$this->jabatan->id, $other->id],
            'approver_ids' => array_map(fn (User $user) => $user->id, $this->approvers),
        ]);

        $this->assertDatabaseCount('approval_lines', 2);
        $this->assertDatabaseCount('approval_line_jabatan', 3);
        $this->assertDatabaseHas('approval_line_jabatan', ['approval_line_id' => $cuti->id, 'jabatan_id' => $this->jabatan->id, 'type' => 'cuti']);
        foreach ([$this->jabatan, $other] as $position) {
            $this->assertDatabaseHas('approval_line_jabatan', ['approval_line_id' => $lembur->id, 'jabatan_id' => $position->id, 'type' => 'lembur']);
        }
        $this->assertSame([1, 2, 3], $lembur->steps->pluck('level')->all());
    }

    public function test_category_filters_scope_rows_overview_and_available_positions(): void
    {
        $cuti = $this->configure(ApprovalLine::TYPE_CUTI);
        $this->actingAs($this->admin)->get(self::LINES.'?type=lembur')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.type', 'lembur')->has('lines.data', 0)
                ->where('overview.configured', 0)->where('jabatanOptions.0.configured', false)
                ->where('typeOptions', [
                    ['value' => 'cuti', 'label' => 'Cuti'],
                    ['value' => 'lembur', 'label' => 'Lembur'],
                    ['value' => 'perubahan-shift', 'label' => 'Perubahan Shift'],
                    ['value' => 'presensi', 'label' => 'Presensi (di luar radius)'],
                ]));

        $lembur = $this->configure(overrides: ['approver_ids' => [$this->approvers[1]->id]]);
        $this->get(self::LINES)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'cuti')->has('lines.data', 1)->where('lines.data.0.id', $cuti->id));
        $this->get(self::LINES.'?type=lembur&search=SIRS')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'lembur')->where('filters.search', 'SIRS')->has('lines.data', 1)
            ->where('lines.data.0.id', $lembur->id)->where('lines.data.0.type', 'lembur')
            ->where('lines.data.0.steps.0.user_id', $this->approvers[1]->id)
            ->where('overview.configured', 1)->where('jabatanOptions.0.configured', true));
        $this->get(self::LINES.'?type=lembur&search=TidakAda')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('lines.data', 0)->where('overview.configured', 1));
    }

    public function test_updating_and_deleting_lembur_preserves_cuti_and_releases_positions(): void
    {
        $cuti = $this->configure(ApprovalLine::TYPE_CUTI);
        $lembur = $this->configure();
        $other = $this->otherPosition();
        // Older clients may omit type; updates must retain the stored category.
        $this->put(self::LINES."/{$lembur->id}", [
            'jabatan_ids' => [$other->id], 'approver_ids' => [$this->approvers[2]->id, $this->approvers[1]->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('lembur', $lembur->fresh()->type);
        $this->assertSame([$other->id], $lembur->fresh()->jabatans->modelKeys());
        $this->assertSame([$this->approvers[2]->id, $this->approvers[1]->id], $lembur->fresh()->steps->pluck('user_id')->all());
        $this->assertSame([$this->approvers[0]->id], $cuti->fresh()->steps->pluck('user_id')->all());
        $replacement = $this->configure();

        $this->delete(self::LINES."/{$lembur->id}")->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('approval_line_jabatan', ['approval_line_id' => $lembur->id]);
        $this->assertDatabaseMissing('approval_line_steps', ['approval_line_id' => $lembur->id]);
        $this->assertDatabaseHas('approval_lines', ['id' => $cuti->id, 'type' => 'cuti']);
        $this->assertDatabaseHas('approval_lines', ['id' => $replacement->id, 'type' => 'lembur']);
    }

    public function test_duplicates_are_rejected_per_category_without_partial_changes(): void
    {
        $this->configure(ApprovalLine::TYPE_CUTI);
        $lembur = $this->configure();
        $this->configure(ApprovalLine::TYPE_PERUBAHAN_SHIFT);
        $this->configure(ApprovalLine::TYPE_PRESENSI);
        foreach (ApprovalLine::types() as $type) {
            $this->post(self::LINES, $this->payload($type))->assertSessionHasErrors('jabatan_ids.0');
        }
        $other = $this->otherPosition();
        $second = $this->configure(overrides: ['jabatan_ids' => [$other->id]]);
        $this->put(self::LINES."/{$second->id}", $this->payload())->assertSessionHasErrors('jabatan_ids.0');
        $this->assertSame([$other->id], $second->fresh()->jabatans->modelKeys());
        $this->assertSame([$this->jabatan->id], $lembur->fresh()->jabatans->modelKeys());
        $this->assertDatabaseCount('approval_lines', 5);
    }

    public function test_invalid_categories_are_rejected_and_existing_categories_cannot_change(): void
    {
        $this->actingAs($this->admin)->getJson(self::LINES.'?type=other')->assertUnprocessable()->assertJsonValidationErrors('type');
        foreach (['other', '', null, ['lembur']] as $type) {
            $this->postJson(self::LINES, array_replace($this->payload(), ['type' => $type]))
                ->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $this->assertDatabaseCount('approval_lines', 0);

        foreach (ApprovalLine::types() as $type) {
            $line = $this->configure($type);
            $differentType = $type === 'cuti' ? 'lembur' : 'cuti';
            $this->put(self::LINES."/{$line->id}", $this->payload($differentType))->assertSessionHasErrors('type');
            $this->assertSame($type, $line->fresh()->type);
            $this->assertDatabaseHas('approval_line_jabatan', ['approval_line_id' => $line->id, 'type' => $type]);
        }
    }

    public function test_lembur_management_uses_existing_approval_line_permissions(): void
    {
        $line = $this->configure();
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::CUTI_VIEW, AccessPermission::CUTI_CREATE]);
        $this->actingAs($user)->get(self::LINES.'?type=lembur')->assertForbidden();
        $this->post(self::LINES, $this->payload())->assertForbidden();
        $this->put(self::LINES."/{$line->id}", $this->payload())->assertForbidden();
        $this->delete(self::LINES."/{$line->id}")->assertForbidden();
        $user->givePermissionTo(AccessPermission::APPROVAL_LINE_VIEW);
        $this->get(self::LINES.'?type=lembur')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)->where('can.update', false)->where('can.delete', false));
    }

    public function test_lembur_requires_active_positions_and_one_to_three_distinct_approvers(): void
    {
        $this->actingAs($this->admin);
        foreach ([[], [999999], [$this->approvers[0]->id, $this->approvers[0]->id], array_merge(array_column($this->approvers, 'id'), [$this->admin->id])] as $ids) {
            $this->post(self::LINES, array_replace($this->payload(), ['approver_ids' => $ids]))->assertSessionHasErrors();
        }
        foreach ([[], [999999], [$this->jabatan->id, $this->jabatan->id]] as $ids) {
            $this->post(self::LINES, array_replace($this->payload(), ['jabatan_ids' => $ids]))->assertSessionHasErrors();
        }
        $this->jabatan->unitKerja->update(['aktif' => false]);
        $this->post(self::LINES, $this->payload())->assertSessionHasErrors('jabatan_ids.0');
        $this->assertDatabaseCount('approval_lines', 0);
    }

    public function test_service_checks_duplicate_positions_within_the_selected_category(): void
    {
        $this->configure(ApprovalLine::TYPE_CUTI);
        $this->configure();
        $this->expectException(ValidationException::class);
        try {
            app(ApprovalLineService::class)->save($this->payload());
        } finally {
            $this->assertDatabaseCount('approval_lines', 2);
            $this->assertDatabaseCount('approval_line_jabatan', 2);
        }
    }

    public function test_lembur_assignments_do_not_grant_access_or_configuration_for_cuti(): void
    {
        $this->configure();
        $approver = $this->approvers[0];
        $this->assertFalse(CutiAccess::allows($approver));
        $this->actingAs($approver)->get('/absensi/admin-web/cuti')->assertForbidden();

        $applicant = User::factory()->create();
        $applicant->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::CUTI_VIEW, AccessPermission::CUTI_CREATE]);
        $employee = Pegawai::query()->create([
            'nama' => 'Pemohon', 'jabatan_id' => $this->jabatan->id, 'unit_kerja_id' => $this->jabatan->unit_kerja_id,
        ]);
        $employee->forceFill(['user_id' => $applicant->id])->save();
        $this->actingAs($applicant)->get('/absensi/admin-web/cuti')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('approvalPreview', 0)
                ->where('unavailableReason', 'Approval line cuti untuk jabatan Anda belum diatur.'));
        $this->post('/absensi/admin-web/cuti', [
            'master_cuti_id' => $this->masterCuti->id,
            'tanggal_mulai' => now()->addDay()->toDateString(), 'tanggal_selesai' => now()->addDays(2)->toDateString(), 'alasan' => 'Keluarga',
        ])->assertSessionHasErrors('cuti');
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    public function test_cuti_submission_uses_cuti_approvers_when_both_categories_exist(): void
    {
        $this->configure(overrides: ['approver_ids' => [$this->approvers[1]->id]]);
        $this->configure(ApprovalLine::TYPE_CUTI);
        $this->assertTrue(CutiAccess::allows($this->approvers[0]));
        $this->assertFalse(CutiAccess::allows($this->approvers[1]));
        $applicant = User::factory()->create();
        $applicant->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::CUTI_VIEW, AccessPermission::CUTI_CREATE]);
        $employee = Pegawai::query()->create([
            'nama' => 'Pemohon', 'jabatan_id' => $this->jabatan->id, 'unit_kerja_id' => $this->jabatan->unit_kerja_id,
        ]);
        $employee->forceFill(['user_id' => $applicant->id])->save();
        PegawaiCuti::query()->create(['master_cuti_id' => $this->masterCuti->id, 'pegawai_id' => $employee->id, 'tahun' => 0, 'jumlah_hari' => 30]);
        $this->actingAs($applicant)->post('/absensi/admin-web/cuti', [
            'master_cuti_id' => $this->masterCuti->id,
            'tanggal_mulai' => now()->addDay()->toDateString(), 'tanggal_selesai' => now()->addDays(2)->toDateString(), 'alasan' => 'Keluarga',
        ])->assertSessionHasNoErrors();
        $this->assertSame([$this->approvers[0]->id], CutiRequest::query()->sole()->approvals->pluck('user_id')->all());
    }
}
