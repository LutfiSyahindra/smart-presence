<?php

namespace Tests\Feature\Absensi\AdminWeb\Setting;

use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationManagementTest extends TestCase
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

    public function test_guests_and_unauthorized_users_cannot_access_the_module(): void
    {
        $this->get('/absensi/admin-web/setting/users')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/setting/users')->assertForbidden();
    }

    public function test_super_admin_can_open_all_management_pages(): void
    {
        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/setting/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/setting/user/Index'));

        $this->get('/absensi/admin-web/setting/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/setting/role/Index'));

        $this->get('/absensi/admin-web/setting/permissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/setting/permission/Index'));
    }

    public function test_authorized_user_can_create_and_update_a_user_with_access_assignments(): void
    {
        $role = Role::create(['name' => 'operator', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'attendance.approve', 'guard_name' => 'web']);

        $this->actingAs($this->admin)->post('/absensi/admin-web/setting/users', [
            'name' => 'Operator Baru',
            'email' => 'operator@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_ids' => [$role->id],
            'permission_ids' => [$permission->id],
        ])->assertRedirect('/absensi/admin-web/setting/users');

        $user = User::query()->where('email', 'operator@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('operator'));
        $this->assertTrue($user->hasDirectPermission('attendance.approve'));

        $this->put("/absensi/admin-web/setting/users/{$user->id}", [
            'name' => 'Operator Diperbarui',
            'email' => 'operator@example.com',
            'password' => '',
            'password_confirmation' => '',
            'role_ids' => [],
            'permission_ids' => [],
        ])->assertRedirect('/absensi/admin-web/setting/users');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Operator Diperbarui']);
        $this->assertFalse($user->refresh()->hasRole('operator'));
        $this->assertFalse($user->hasDirectPermission('attendance.approve'));
    }

    public function test_user_cannot_delete_their_own_account_from_admin_module(): void
    {
        $this->actingAs($this->admin)
            ->delete("/absensi/admin-web/setting/users/{$this->admin->id}")
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $this->actingAs($this->admin)
            ->put("/absensi/admin-web/setting/users/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'password' => '',
                'password_confirmation' => '',
                'role_ids' => [],
                'permission_ids' => [],
            ])
            ->assertSessionHasErrors('role_ids');

        $this->assertTrue($this->admin->refresh()->hasRole('super-admin'));
    }

    public function test_role_can_be_created_updated_and_deleted(): void
    {
        $permission = Permission::findByName(AccessPermission::USERS_VIEW);

        $this->actingAs($this->admin)->post('/absensi/admin-web/setting/roles', [
            'name' => 'kepala-unit',
            'permission_ids' => [$permission->id],
        ])->assertRedirect('/absensi/admin-web/setting/roles');

        $role = Role::findByName('kepala-unit');
        $this->assertTrue($role->hasPermissionTo(AccessPermission::USERS_VIEW));

        $this->put("/absensi/admin-web/setting/roles/{$role->id}", [
            'name' => 'kepala-bagian',
            'permission_ids' => [],
        ])->assertRedirect('/absensi/admin-web/setting/roles');

        $this->delete("/absensi/admin-web/setting/roles/{$role->id}")
            ->assertRedirect('/absensi/admin-web/setting/roles');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_role_management_page_exposes_complete_overview_metrics(): void
    {
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        $role->givePermissionTo(AccessPermission::USERS_VIEW);
        User::factory()->create()->assignRole($role);

        $roles = Role::query()->where('guard_name', 'web')->withCount(['permissions', 'users'])->get();

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/setting/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/setting/role/Index')
                ->where('overview', [
                    'total' => $roles->count(),
                    'in_use' => $roles->where('users_count', '>', 0)->count(),
                    'with_permissions' => $roles->where('permissions_count', '>', 0)->count(),
                    'user_assignments' => (int) $roles->sum('users_count'),
                ]));
    }

    public function test_custom_permissions_are_manageable_but_system_permissions_are_protected(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/setting/permissions', [
            'name' => 'attendance.approve',
        ])->assertRedirect('/absensi/admin-web/setting/permissions');

        $permission = Permission::findByName('attendance.approve');
        $this->assertTrue(Role::findByName('super-admin')->hasPermissionTo($permission));

        $this->put("/absensi/admin-web/setting/permissions/{$permission->id}", [
            'name' => 'attendance.validate',
        ])->assertRedirect('/absensi/admin-web/setting/permissions');

        $this->delete("/absensi/admin-web/setting/permissions/{$permission->id}")
            ->assertRedirect('/absensi/admin-web/setting/permissions');
        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);

        $systemPermission = Permission::findByName(AccessPermission::USERS_VIEW);
        $this->delete("/absensi/admin-web/setting/permissions/{$systemPermission->id}")
            ->assertSessionHasErrors('permission');
        $this->assertDatabaseHas('permissions', ['id' => $systemPermission->id]);
    }

    public function test_permission_management_page_exposes_complete_overview_metrics(): void
    {
        $permission = Permission::create(['name' => 'reports.export', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'reporter', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        User::factory()->create()->givePermissionTo($permission);

        $permissions = Permission::query()->where('guard_name', 'web')->withCount(['roles', 'users'])->get();

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/setting/permissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/setting/permission/Index')
                ->where('overview', [
                    'total' => $permissions->count(),
                    'in_use' => $permissions
                        ->filter(fn (Permission $item) => $item->roles_count > 0 || $item->users_count > 0)
                        ->count(),
                    'system' => $permissions->whereIn('name', AccessPermission::all())->count(),
                    'role_assignments' => (int) $permissions->sum('roles_count'),
                    'user_assignments' => (int) $permissions->sum('users_count'),
                ]));
    }
}
