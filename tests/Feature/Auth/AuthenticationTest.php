<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Portal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

    public function test_web_logins_are_remembered_without_an_optional_checkbox(): void
    {
        $user = User::factory()->create();
        $cookieName = Auth::guard('web')->getRecallerName();

        foreach (['/login', '/absensi/admin-web/login'] as $loginUrl) {
            $this->flushSession();
            Auth::forgetGuards();

            $response = $this->post($loginUrl, [
                'email' => $user->email,
                'password' => 'password',
                'remember' => false,
            ]);

            $response->assertRedirect()->assertCookieNotExpired($cookieName);
            $this->assertAuthenticatedAs($user);
            $this->assertGreaterThan(now()->addDays(30)->timestamp, $response->getCookie($cookieName)->getExpiresTime());
            $this->assertNotEmpty($user->fresh()->getRememberToken());
        }
    }

    public function test_remembered_login_survives_an_expired_session_and_logout_revokes_it(): void
    {
        $user = User::factory()->create();
        $cookieName = Auth::guard('web')->getRecallerName();
        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $rememberCookie = $login->getCookie($cookieName)->getValue();

        // A fresh browser session has no server-side login state.
        $this->flushSession();
        Auth::forgetGuards();
        $this->travel(45)->days();

        $this->withCookie($cookieName, $rememberCookie)
            ->get('/login')
            ->assertRedirect('/absensi/mobile');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard('web')->viaRemember());

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertCookieExpired($cookieName);
        $this->assertGuest();

        // Even replaying the old cookie cannot sign the user back in.
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCookie($cookieName, $rememberCookie)->get('/login')->assertOk();
        $this->assertGuest();
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
