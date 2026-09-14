<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Jabatan;
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

    public function test_super_admin_can_create_update_and_delete_jabatan(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'kode' => ' kep-unit ',
            'nama' => '  Kepala   Unit  ',
            'deskripsi' => '  Mengelola   operasional unit. ',
            'aktif' => true,
        ])->assertRedirect('/absensi/admin-web/master-data/jabatan');

        $jabatan = Jabatan::query()->where('kode', 'KEP-UNIT')->firstOrFail();
        $this->assertSame('Kepala Unit', $jabatan->nama);
        $this->assertSame('Mengelola operasional unit.', $jabatan->deskripsi);
        $this->assertTrue($jabatan->aktif);

        $this->put("/absensi/admin-web/master-data/jabatan/{$jabatan->id}", [
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
    }

    public function test_validation_rejects_duplicate_and_invalid_codes(): void
    {
        Jabatan::query()->create([
            'kode' => 'DOKTER',
            'nama' => 'Dokter Umum',
            'aktif' => true,
        ]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'kode' => 'dokter',
            'nama' => 'Dokter Spesialis',
            'aktif' => true,
        ])->assertSessionHasErrors('kode');

        $this->post('/absensi/admin-web/master-data/jabatan', [
            'kode' => 'KODE TIDAK VALID',
            'nama' => 'Perawat',
            'aktif' => true,
        ])->assertSessionHasErrors('kode');
    }

    public function test_super_admin_can_create_multiple_jabatans_in_one_request(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
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
            'kode' => 'DIR-MEDIS',
            'nama' => 'Direktur Medis',
            'deskripsi' => 'Memimpin layanan medis.',
            'aktif' => true,
        ]);
        $this->assertDatabaseHas('jabatans', [
            'kode' => 'PERAWAT-UGD',
            'nama' => 'Perawat UGD',
            'deskripsi' => null,
            'aktif' => false,
        ]);
    }

    public function test_dynamic_jabatan_input_rejects_duplicates_within_the_same_request(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/jabatan', [
            'jabatans' => [
                ['kode' => 'DOKTER', 'nama' => 'Dokter Umum', 'aktif' => true],
                ['kode' => 'dokter', 'nama' => 'Dokter Spesialis', 'aktif' => true],
            ],
        ])->assertSessionHasErrors('jabatans.0.kode');

        $this->assertDatabaseCount('jabatans', 0);
    }

    public function test_index_exposes_overview_and_applies_search_and_status_filters(): void
    {
        Jabatan::query()->create(['kode' => 'DIR', 'nama' => 'Direktur', 'aktif' => true]);
        Jabatan::query()->create(['kode' => 'PER', 'nama' => 'Perawat', 'aktif' => true]);
        Jabatan::query()->create(['kode' => 'ARSIP', 'nama' => 'Arsiparis', 'aktif' => false]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/jabatan?search=per&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/jabatan/Index')
                ->where('overview.total', 3)
                ->where('overview.active', 2)
                ->where('overview.inactive', 1)
                ->where('jabatans.total', 1)
                ->where('jabatans.data.0.kode', 'PER')
                ->where('filters.search', 'per')
                ->where('filters.status', 'active'));
    }

    public function test_action_routes_require_the_corresponding_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findByName(AccessPermission::JABATAN_VIEW));
        $jabatan = Jabatan::query()->create(['kode' => 'FAR', 'nama' => 'Apoteker', 'aktif' => true]);

        $this->actingAs($user)
            ->post('/absensi/admin-web/master-data/jabatan', ['kode' => 'ADM', 'nama' => 'Administrator', 'aktif' => true])
            ->assertForbidden();
        $this->put("/absensi/admin-web/master-data/jabatan/{$jabatan->id}", ['kode' => 'FAR', 'nama' => 'Farmasi', 'aktif' => true])
            ->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/jabatan/{$jabatan->id}")->assertForbidden();
    }
}
