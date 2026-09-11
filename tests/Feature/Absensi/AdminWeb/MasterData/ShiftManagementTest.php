<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\Shift;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftManagementTest extends TestCase
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
        $this->get('/absensi/admin-web/master-data/shift')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/master-data/shift')->assertForbidden();

        $user->givePermissionTo(AccessPermission::SHIFT_VIEW);

        $this->get('/absensi/admin-web/master-data/shift')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/shift/Index')
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
    }

    public function test_super_admin_can_create_update_and_delete_shift(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/shift', [
            'nama' => '  Shift   Pagi  ',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ])->assertRedirect('/absensi/admin-web/master-data/shift');

        $shift = Shift::query()->where('nama', 'Shift Pagi')->firstOrFail();
        $this->assertSame('07:00', substr($shift->jam_masuk, 0, 5));
        $this->assertSame('15:00', substr($shift->jam_keluar, 0, 5));

        $this->put("/absensi/admin-web/master-data/shift/{$shift->id}", [
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ])->assertRedirect('/absensi/admin-web/master-data/shift');

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ]);

        $this->delete("/absensi/admin-web/master-data/shift/{$shift->id}")
            ->assertRedirect('/absensi/admin-web/master-data/shift');
        $this->assertDatabaseMissing('shifts', ['id' => $shift->id]);
    }

    public function test_super_admin_can_create_multiple_shifts_from_dynamic_input(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/shift', [
            'shifts' => [
                ['nama' => ' Shift Pagi ', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00'],
                ['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00'],
            ],
        ])->assertRedirect('/absensi/admin-web/master-data/shift')
            ->assertSessionHas('success', '2 shift berhasil ditambahkan.');

        $this->assertDatabaseHas('shifts', ['nama' => 'Shift Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->assertDatabaseHas('shifts', ['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
    }

    public function test_dynamic_input_rejects_duplicate_names_and_invalid_times(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/shift', [
            'shifts' => [
                ['nama' => 'Shift Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00'],
                ['nama' => 'shift pagi', 'jam_masuk' => '07:00 AM', 'jam_keluar' => '23:00'],
            ],
        ])->assertSessionHasErrors(['shifts.0.nama', 'shifts.1.jam_masuk']);

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_index_exposes_overview_and_applies_search_filter(): void
    {
        Shift::query()->create(['nama' => 'Shift Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        Shift::query()->create(['nama' => 'Shift Siang', 'jam_masuk' => '13:00', 'jam_keluar' => '21:00']);
        Shift::query()->create(['nama' => 'Shift Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/shift?search=malam')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/shift/Index')
                ->where('overview.total', 3)
                ->where('overview.morning', 1)
                ->where('overview.afternoon', 1)
                ->where('overview.night', 1)
                ->where('shifts.total', 1)
                ->where('shifts.data.0.nama', 'Shift Malam')
                ->where('shifts.data.0.jam_masuk', '22:00')
                ->where('shifts.data.0.jam_keluar', '06:00')
                ->where('filters.search', 'malam'));
    }

    public function test_action_routes_require_the_corresponding_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::SHIFT_VIEW);
        $shift = Shift::query()->create(['nama' => 'Shift Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);

        $this->actingAs($user)
            ->post('/absensi/admin-web/master-data/shift', ['nama' => 'Shift Siang', 'jam_masuk' => '13:00', 'jam_keluar' => '21:00'])
            ->assertForbidden();
        $this->put("/absensi/admin-web/master-data/shift/{$shift->id}", ['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00'])
            ->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/shift/{$shift->id}")->assertForbidden();
    }
}
