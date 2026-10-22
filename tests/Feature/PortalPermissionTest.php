<?php

namespace Tests\Feature;

use App\Models\ApprovalLine;
use App\Models\User;
use App\Services\Absensi\AdminWeb\Setting\AuthorizationFormDataService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortalPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
    }

    public static function restrictedEndpoints(): array
    {
        return [
            ['GET', '/absensi/admin-web/dashboard', 'admin.access'],
            ['GET', '/absensi/admin-web/cuti', 'admin.access'],
            ['POST', '/absensi/admin-web/cuti', 'admin.access'],
            ['GET', '/absensi/admin-web/notifikasi/semua', 'admin.access'],
            ['GET', '/absensi/admin-web/notifikasi/feed', 'admin.access'],
            ['POST', '/absensi/admin-web/notifikasi/read-all', 'admin.access'],
            ['POST', '/absensi/admin-web/notifikasi/approve-many', 'admin.access'],
            ['GET', '/absensi/mobile/presensi', 'mobile.access'],
            ['GET', '/absensi/mobile/presensi/log', 'mobile.access'],
            ['GET', '/absensi/mobile/clock-in', 'mobile.access'],
            ['POST', '/absensi/mobile/clock-in', 'mobile.access'],
            ['GET', '/absensi/mobile/clock-out', 'mobile.access'],
            ['POST', '/absensi/mobile/clock-out', 'mobile.access'],
            ['GET', '/absensi/mobile/cuti', 'mobile.access'],
            ['POST', '/absensi/mobile/cuti', 'mobile.access'],
            ['GET', '/absensi/mobile/notifikasi', 'mobile.access'],
            ['GET', '/absensi/mobile/notifikasi/feed', 'mobile.access'],
            ['POST', '/absensi/mobile/notifikasi/read-all', 'mobile.access'],
            ['POST', '/absensi/mobile/notifikasi/cuti/approve-many', 'mobile.access'],
        ];
    }

    #[DataProvider('restrictedEndpoints')]
    public function test_portal_access_alone_does_not_grant_feature_access(string $method, string $uri, string $access): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($access);
        $this->actingAs($user)->json($method, $uri)->assertForbidden();
    }

    public function test_mobile_permissions_can_be_granted_and_revoked_independently(): void
    {
        $user = User::factory()->create();
        $user->assignRole('pegawai');
        Role::findByName('pegawai')->revokePermissionTo(AccessPermission::MOBILE_HISTORY);

        $this->actingAs($user)->get('/absensi/mobile')->assertOk();
        $this->get('/absensi/mobile/presensi')->assertOk();
        $this->get('/absensi/mobile/presensi/log')->assertForbidden();
        $user->givePermissionTo(AccessPermission::MOBILE_HISTORY);
        $this->get('/absensi/mobile/presensi/log')->assertOk();
    }

    public function test_api_actions_require_their_own_permission_and_publish_effective_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::MOBILE_ACCESS, AccessPermission::MOBILE_ATTENDANCE]);
        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/v1/attendance')->assertOk();
        $this->getJson('/api/mobile/v1/attendance/history')->assertForbidden();
        foreach (['clock-in', 'clock-out'] as $action) {
            $this->getJson('/api/mobile/v1/attendance/'.$action)->assertForbidden();
            $this->postJson('/api/mobile/v1/attendance/'.$action)->assertForbidden();
        }
        $this->getJson('/api/mobile/v1/auth/user')->assertOk()
            ->assertJsonPath('user.permissions', [AccessPermission::MOBILE_ACCESS, AccessPermission::MOBILE_ATTENDANCE]);
    }

    public function test_permission_directory_filters_platform_before_pagination_and_search(): void
    {
        $admin = User::factory()->create()->assignRole('super-admin');
        foreach (['web', 'mobile'] as $platform) {
            $expected = Permission::query()->where('guard_name', 'web')
                ->where('name', $platform === 'mobile' ? 'like' : 'not like', 'mobile.%')->count();
            $this->actingAs($admin)->get('/absensi/admin-web/setting/permissions?platform='.$platform)
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.platform', $platform)->where('permissions.total', $expected)
                ->where('permissions.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['platform'] === $platform)));
        }
        $this->get('/absensi/admin-web/setting/permissions?platform=mobile&search=cuti')
            ->assertInertia(fn (Assert $page) => $page->where('permissions.total', 4));
    }

    public function test_admin_without_dashboard_permission_lands_on_an_allowed_module(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::USERS_VIEW]);
        $this->actingAs($user)->post('/portal/admin')->assertRedirect('/absensi/admin-web/setting/users');
        $this->get('/absensi/admin-web/dashboard')->assertForbidden();
    }

    public function test_custom_permission_without_mobile_prefix_does_not_mix_platform_groups(): void
    {
        Permission::create(['name' => 'mobile', 'guard_name' => 'web']);
        $groups = collect(app(AuthorizationFormDataService::class)->permissionGroups());
        $this->assertSame(['mobile'], collect($groups->where('group', 'Mobile')->firstWhere('platform', 'web')['permissions'])->pluck('name')->all());
        $this->assertCount(count(AccessPermission::mobile()), $groups->firstWhere('platform', 'mobile')['permissions']);
    }

    public function test_migration_preserves_role_direct_and_existing_approver_access_idempotently(): void
    {
        $role = Role::create(['name' => 'custom-mobile', 'guard_name' => 'web']);
        $role->givePermissionTo(AccessPermission::MOBILE_ACCESS);
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $approver = User::factory()->create();
        $line = ApprovalLine::create(['type' => 'cuti']);
        $line->steps()->create(['user_id' => $approver->id, 'level' => 1]);

        $migration = require database_path('migrations/2026_10_21_000001_complete_portal_permissions.php');
        $migration->down();
        $migration->up();
        $migration->up();

        $this->assertTrue($role->fresh()->hasPermissionTo(AccessPermission::MOBILE_HISTORY));
        $this->assertFalse($role->fresh()->hasPermissionTo(AccessPermission::MOBILE_ACCOUNT));
        $this->assertFalse($role->fresh()->hasPermissionTo(AccessPermission::CUTI_VIEW));
        $this->assertTrue($user->fresh()->hasDirectPermission(AccessPermission::DASHBOARD_VIEW));
        $this->assertTrue($approver->fresh()->hasDirectPermission(AccessPermission::CUTI_APPROVE));
        $this->assertFalse($approver->fresh()->can(AccessPermission::ADMIN_ACCESS));
        $this->assertSame(count(AccessPermission::all()), Permission::where('guard_name', 'web')->count());
    }
}
