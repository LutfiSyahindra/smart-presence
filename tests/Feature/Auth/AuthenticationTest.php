<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Portal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('portal', Portal::PRESENSI)
                ->where('loginUrl', '/login'));
    }

    public function test_admin_login_screen_uses_the_admin_portal_context()
    {
        $response = $this->get('/absensi/admin-web/login');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('portal', Portal::ADMIN)
                ->where('loginUrl', '/absensi/admin-web/login'));
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('absensi.mobile.home', absolute: false));
        $response->assertSessionHas(Portal::SESSION_KEY, Portal::PRESENSI);
    }

    public function test_employee_who_is_also_an_admin_stays_in_presensi_from_the_default_login()
    {
        $this->seed(AuthorizationSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(['pegawai', 'super-admin']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('absensi.mobile.home', absolute: false));
        $response->assertSessionHas(Portal::SESSION_KEY, Portal::PRESENSI);
    }

    public function test_employee_who_is_also_an_admin_can_explicitly_enter_the_admin_portal()
    {
        $this->seed(AuthorizationSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(['pegawai', 'super-admin']);

        $response = $this->post('/absensi/admin-web/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('absensi.admin-web.dashboard', absolute: false));
        $response->assertSessionHas(Portal::SESSION_KEY, Portal::ADMIN);
    }

    public function test_user_without_admin_access_falls_back_to_presensi_from_admin_login()
    {
        $user = User::factory()->create();

        $response = $this->post('/absensi/admin-web/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('absensi.mobile.home', absolute: false));
        $response->assertSessionHas(Portal::SESSION_KEY, Portal::PRESENSI);
    }

    public function test_an_authenticated_admin_is_redirected_by_the_login_portal_they_open()
    {
        $this->seed(AuthorizationSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(['pegawai', 'super-admin']);

        $this->actingAs($user)
            ->withSession([Portal::SESSION_KEY => Portal::ADMIN])
            ->get('/login')
            ->assertRedirect('/absensi/mobile');

        $this->withSession([Portal::SESSION_KEY => Portal::PRESENSI])
            ->get('/absensi/admin-web/login')
            ->assertRedirect('/absensi/admin-web/dashboard');
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_admin_logout_returns_to_the_admin_login()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([Portal::SESSION_KEY => Portal::ADMIN])
            ->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/absensi/admin-web/login');
    }
}
