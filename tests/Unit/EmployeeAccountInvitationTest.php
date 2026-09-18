<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use Tests\TestCase;

class EmployeeAccountInvitationTest extends TestCase
{
    public function test_activation_url_uses_the_configured_application_url(): void
    {
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
}
