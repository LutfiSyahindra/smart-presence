<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Portal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_legacy_dashboard_redirects_authenticated_users_to_presensi_by_default()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertRedirect('/absensi/mobile');
    }

    public function test_authenticated_users_can_visit_the_mobile_portal_placeholder()
    {
        $this->get('/absensi/mobile')->assertRedirect('/login');

        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('pegawai');

        $this->actingAs($user)
            ->get('/absensi/mobile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Home')
                ->where('canAccessAdmin', false));
    }

    public function test_user_without_attendance_access_cannot_visit_the_mobile_portal()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/absensi/mobile')
            ->assertForbidden();
    }

    public function test_admin_dashboard_has_its_own_login_and_permission_boundary()
    {
        $this->get('/absensi/admin-web/dashboard')
            ->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/absensi/admin-web/dashboard')
            ->assertForbidden();

        $this->seed(AuthorizationSeeder::class);
        $user->assignRole('super-admin');

        $this->get('/absensi/admin-web/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_portal_switch_is_explicit_and_permission_protected()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/portal/admin')
            ->assertForbidden();

        $this->seed(AuthorizationSeeder::class);
        $user->assignRole('super-admin');

        $this->post('/portal/admin')
            ->assertRedirect('/absensi/admin-web/dashboard')
            ->assertSessionHas(Portal::SESSION_KEY, Portal::ADMIN);

        $this->post('/portal/presensi')
            ->assertRedirect('/absensi/mobile')
            ->assertSessionHas(Portal::SESSION_KEY, Portal::PRESENSI);
    }
}
