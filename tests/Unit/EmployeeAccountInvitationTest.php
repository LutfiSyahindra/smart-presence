<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAccountInvitationTest extends TestCase
{
    #[DataProvider('emptyActivationUrls')]
    public function test_activation_url_uses_the_configured_application_url(?string $activationUrl): void
    {
        config()->set('app.account_activation_url', $activationUrl);
        config()->set('app.url', 'http://192.168.2.146:8600');
        app('url')->forceRootUrl('http://localhost:8600');

        $user = new User([
            'name' => 'Pegawai Undangan',
            'email' => 'undangan@rsarsy.co.id',
        ]);
        $mail = (new EmployeeAccountInvitation('token-contoh'))->toMail($user);

        $this->assertSame(
            'http://192.168.2.146:8600/reset-password/token-contoh?email=undangan%40rsarsy.co.id&invitation=1',
            $mail->actionUrl,
        );
    }

    public static function emptyActivationUrls(): array
    {
        return [
            'not configured' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('customActivationUrls')]
    public function test_activation_url_uses_the_public_url_instead_of_the_application_or_request_url(string $activationUrl): void
    {
        config()->set('app.account_activation_url', $activationUrl);
        config()->set('app.url', 'http://localhost:8080');
        app('url')->forceRootUrl('http://localhost:8600');

        $user = new User([
            'name' => 'Pegawai Undangan',
            'email' => 'pegawai+undangan@rsarsy.co.id',
        ]);
        $mail = (new EmployeeAccountInvitation('token-contoh'))->toMail($user);

        $this->assertSame(
            'https://presensi.example.com/reset-password/token-contoh?email=pegawai%2Bundangan%40rsarsy.co.id&invitation=1',
            $mail->actionUrl,
        );
    }

    public static function customActivationUrls(): array
    {
        return [
            'domain' => ['https://presensi.example.com'],
            'trailing slash' => ['https://presensi.example.com/'],
            'surrounding whitespace' => [' https://presensi.example.com/ '],
        ];
    }
}
