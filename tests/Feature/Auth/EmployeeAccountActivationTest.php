<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_pending_employees_can_open_an_invitation_without_the_query_marker(): void
    {
        $user = $this->pendingEmployee();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ResetPassword')
                ->where('invitation', true)
                ->where('invitationError', null));
    }

    #[DataProvider('invalidInvitations')]
    public function test_invalid_invitations_keep_the_activation_context(string $state): void
    {
        $user = $this->pendingEmployee();
        $token = Password::createToken($user);
        $url = (new EmployeeAccountInvitation($token))->toMail($user)->actionUrl;

        switch ($state) {
            case 'expired':
                $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
                break;
            case 'replaced':
                $latestToken = Password::createToken($user);
                $this->get((new EmployeeAccountInvitation($latestToken))->toMail($user)->actionUrl)
                    ->assertOk()
                    ->assertInertia(fn (Assert $page) => $page
                        ->where('invitation', true)
                        ->where('invitationError', null));
                break;
            case 'used':
                $this->post(route('password.store'), [
                    'token' => $token,
                    'email' => $user->email,
                    'password' => 'PasswordBaru123!',
                    'password_confirmation' => 'PasswordBaru123!',
                ])->assertSessionHasNoErrors()->assertRedirect(route('home'));
                break;
            case 'invalid token':
                $url = (new EmployeeAccountInvitation('invalid-token'))->toMail($user)->actionUrl;
                break;
            case 'unknown email':
                $url = route('password.reset', [
                    'token' => $token,
                    'email' => 'unknown@example.com',
                    'invitation' => 1,
                ]);
                break;
        }

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ResetPassword')
                ->where('invitation', true)
                ->where('invitationError', 'Tautan aktivasi sudah tidak berlaku atau tidak valid. Gunakan tautan dari email aktivasi terbaru. Jika masih bermasalah, hubungi administrator untuk meminta undangan baru.'));

        if ($state !== 'unknown email') {
            $this->post(route('password.store'), [
                'token' => $state === 'invalid token' ? 'invalid-token' : $token,
                'email' => $user->email,
                'password' => 'PasswordLain123!',
                'password_confirmation' => 'PasswordLain123!',
            ])->assertSessionHasErrors('email');
        }
    }

    public static function invalidInvitations(): array
    {
        return [
            'expired' => ['expired'],
            'replaced by a resend' => ['replaced'],
            'already used' => ['used'],
            'invalid token' => ['invalid token'],
            'unknown email' => ['unknown email'],
        ];
    }

    public function test_active_employees_still_open_the_normal_password_reset_form(): void
    {
        $user = User::factory()->create();
        $user->pegawai()->create(['nama' => $user->name, 'email' => $user->email]);

        $this->get(route('password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->email,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ResetPassword')
                ->where('invitation', false)
                ->where('invitationError', null));
    }

    private function pendingEmployee(): User
    {
        $user = User::factory()->unverified()->create();
        $user->pegawai()->create(['nama' => $user->name, 'email' => $user->email]);

        return $user;
    }
}
