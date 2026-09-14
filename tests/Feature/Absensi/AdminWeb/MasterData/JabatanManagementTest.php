<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Jabatan;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class JabatanManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_access_is_protected_by_authentication_and_permission(): void
    {
        $this->get('/absensi/admin-web/master-data/jabatan')->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/master-data/jabatan')->assertForbidden();

        $user->givePermissionTo(AccessPermission::JABATAN_VIEW);
        $this->get('/absensi/admin-web/master-data/jabatan')->assertForbidden();

        $user->givePermissionTo(AccessPermission::ADMIN_ACCESS);

        $this->get('/absensi/admin-web/master-data/jabatan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/jabatan/Index')
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
    }

    public function test_super_admin_can_create_unit_with_jabatans_then_manage_each_record(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan/units', [
            'kode' => ' igd ',
            'nama' => '  Instalasi   Gawat Darurat ',
            'deskripsi' => '  Pelayanan   kegawatdaruratan. ',
            'aktif' => true,
            'jabatans' => [
                [
                    'kode' => ' kep-unit ',
                    'nama' => '  Kepala   Unit  ',
                    'deskripsi' => '  Mengelola   operasional unit. ',
                    'aktif' => true,
                ],
            ],
        ])->assertRedirect('/absensi/admin-web/master-data/jabatan');

        $unit = UnitKerja::query()->where('kode', 'IGD')->firstOrFail();
        $this->assertSame('Instalasi Gawat Darurat', $unit->nama);
        $this->assertSame('Pelayanan kegawatdaruratan.', $unit->deskripsi);

        $jabatan = Jabatan::query()->where('kode', 'KEP-UNIT')->firstOrFail();
        $this->assertSame($unit->id, $jabatan->unit_kerja_id);
        $this->assertSame('Kepala Unit', $jabatan->nama);
        $this->assertSame('Mengelola operasional unit.', $jabatan->deskripsi);
        $this->assertTrue($jabatan->aktif);

        $this->put("/absensi/admin-web/master-data/jabatan/{$jabatan->id}", [
            'unit_kerja_id' => $unit->id,
            'kode' => 'KABAG',
            'nama' => 'Kepala Bagian',
            'deskripsi' => '',
            'aktif' => false,
        ])->assertRedirect('/absensi/admin-web/master-data/jabatan');

        $this->assertDatabaseHas('jabatans', [
            'id' => $jabatan->id,
            'kode' => 'KABAG',
            'nama' => 'Kepala Bagian',
            'deskripsi' => null,
            'aktif' => false,
        ]);

        $this->delete("/absensi/admin-web/master-data/jabatan/{$jabatan->id}")
            ->assertRedirect('/absensi/admin-web/master-data/jabatan');
        $this->assertDatabaseMissing('jabatans', ['id' => $jabatan->id]);

        $this->put("/absensi/admin-web/master-data/jabatan/units/{$unit->id}", [
            'kode' => 'IGD-24',
            'nama' => 'IGD 24 Jam',
            'deskripsi' => '',
            'aktif' => false,
        ])->assertRedirect('/absensi/admin-web/master-data/jabatan');
        $this->assertDatabaseHas('unit_kerjas', ['id' => $unit->id, 'kode' => 'IGD-24', 'aktif' => false]);

        $this->delete("/absensi/admin-web/master-data/jabatan/units/{$unit->id}")
            ->assertRedirect('/absensi/admin-web/master-data/jabatan');
        $this->assertDatabaseMissing('unit_kerjas', ['id' => $unit->id]);
    }

    public function test_validation_rejects_duplicate_and_invalid_codes(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'MED', 'nama' => 'Medis', 'aktif' => true]);
        Jabatan::query()->create([
            'unit_kerja_id' => $unit->id,
            'kode' => 'DOKTER',
            'nama' => 'Dokter Umum',
            'aktif' => true,
        ]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'unit_kerja_id' => $unit->id,
            'kode' => 'dokter',
            'nama' => 'Dokter Spesialis',
            'aktif' => true,
        ])->assertSessionHasErrors('kode');

        $this->post('/absensi/admin-web/master-data/jabatan', [
            'unit_kerja_id' => $unit->id,
            'kode' => 'KODE TIDAK VALID',
            'nama' => 'Perawat',
            'aktif' => true,
        ])->assertSessionHasErrors('kode');
    }

    public function test_super_admin_can_create_multiple_jabatans_in_one_request(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'MED', 'nama' => 'Medis', 'aktif' => true]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'unit_kerja_id' => $unit->id,
            'jabatans' => [
                [
                    'kode' => ' dir-medis ',
                    'nama' => '  Direktur   Medis ',
                    'deskripsi' => '  Memimpin   layanan medis. ',
                    'aktif' => true,
                ],
                [
                    'kode' => 'perawat-ugd',
                    'nama' => 'Perawat UGD',
                    'deskripsi' => '',
                    'aktif' => false,
                ],
            ],
        ])->assertRedirect('/absensi/admin-web/master-data/jabatan')
            ->assertSessionHas('success', '2 jabatan berhasil ditambahkan.');

        $this->assertDatabaseHas('jabatans', [
            'unit_kerja_id' => $unit->id,
            'kode' => 'DIR-MEDIS',
            'nama' => 'Direktur Medis',
            'deskripsi' => 'Memimpin layanan medis.',
            'aktif' => true,
        ]);
        $this->assertDatabaseHas('jabatans', [
            'unit_kerja_id' => $unit->id,
            'kode' => 'PERAWAT-UGD',
            'nama' => 'Perawat UGD',
            'deskripsi' => null,
            'aktif' => false,
        ]);
    }

    public function test_dynamic_jabatan_input_rejects_duplicates_within_the_same_request(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'MED', 'nama' => 'Medis', 'aktif' => true]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'unit_kerja_id' => $unit->id,
            'jabatans' => [
                ['kode' => 'DOKTER', 'nama' => 'Dokter Umum', 'aktif' => true],
                ['kode' => 'dokter', 'nama' => 'Dokter Spesialis', 'aktif' => true],
            ],
        ])->assertSessionHasErrors('jabatans.0.kode');

        $this->assertDatabaseCount('jabatans', 0);
    }

    public function test_index_exposes_overview_and_applies_search_and_status_filters(): void
    {
        $medis = UnitKerja::query()->create(['kode' => 'MED', 'nama' => 'Pelayanan Medis', 'aktif' => true]);
        $umum = UnitKerja::query()->create(['kode' => 'UMUM', 'nama' => 'Administrasi Umum', 'aktif' => false]);
        Jabatan::query()->create(['unit_kerja_id' => $medis->id, 'kode' => 'DIR', 'nama' => 'Direktur', 'aktif' => true]);
        Jabatan::query()->create(['unit_kerja_id' => $medis->id, 'kode' => 'PER', 'nama' => 'Perawat', 'aktif' => true]);
        Jabatan::query()->create(['unit_kerja_id' => $umum->id, 'kode' => 'ARSIP', 'nama' => 'Arsiparis', 'aktif' => false]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/jabatan?search=per&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/jabatan/Index')
                ->where('overview.total_units', 2)
                ->where('overview.active_units', 1)
                ->where('overview.total_jabatans', 3)
                ->where('overview.active_jabatans', 2)
                ->where('units.total', 1)
                ->where('units.data.0.kode', 'MED')
                ->has('units.data.0.jabatans', 2)
                ->where('units.data.0.jabatans.1.kode', 'PER')
                ->where('filters.search', 'per')
                ->where('filters.status', 'active'));
    }

    public function test_jabatan_codes_and_names_are_unique_per_unit(): void
    {
        $unitA = UnitKerja::query()->create(['kode' => 'A', 'nama' => 'Unit A', 'aktif' => true]);
        $unitB = UnitKerja::query()->create(['kode' => 'B', 'nama' => 'Unit B', 'aktif' => true]);
        Jabatan::query()->create(['unit_kerja_id' => $unitA->id, 'kode' => 'STAF', 'nama' => 'Staf', 'aktif' => true]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'unit_kerja_id' => $unitB->id,
            'kode' => 'STAF',
            'nama' => 'Staf',
            'aktif' => true,
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('jabatans', 2);
    }

    public function test_action_routes_require_the_corresponding_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findByName(AccessPermission::JABATAN_VIEW));
        $unit = UnitKerja::query()->create(['kode' => 'FAR', 'nama' => 'Farmasi', 'aktif' => true]);
        $jabatan = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'APT', 'nama' => 'Apoteker', 'aktif' => true]);

        $this->actingAs($user)
            ->post('/absensi/admin-web/master-data/jabatan', ['unit_kerja_id' => $unit->id, 'kode' => 'ADM', 'nama' => 'Administrator', 'aktif' => true])
            ->assertForbidden();
        $this->put("/absensi/admin-web/master-data/jabatan/{$jabatan->id}", ['unit_kerja_id' => $unit->id, 'kode' => 'APT', 'nama' => 'Farmasi', 'aktif' => true])
            ->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/jabatan/{$jabatan->id}")->assertForbidden();
        $this->post('/absensi/admin-web/master-data/jabatan/units', ['kode' => 'NEW', 'nama' => 'Unit Baru', 'aktif' => true, 'jabatans' => []])
            ->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/jabatan/units/{$unit->id}")->assertForbidden();
    }
}
