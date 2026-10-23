<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationPageTest extends TestCase
{
    private string $temporaryPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('mobile.android_apk_url', null);
        $this->temporaryPublicPath = sys_get_temp_dir().'/rsarsy-installation-'.Str::uuid();
        File::makeDirectory($this->temporaryPublicPath.'/downloads', recursive: true);
        $this->app->usePublicPath($this->temporaryPublicPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPublicPath);

        parent::tearDown();
    }

    public function test_guests_can_view_installation_with_no_apk_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('rel="manifest"', escape: false)
            ->assertSee('name="apple-mobile-web-app-capable" content="yes"', escape: false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('androidApkUrl', null)
                ->where('status', null));
    }

    public function test_local_distribution_apk_is_available_for_download(): void
    {
        File::put($this->temporaryPublicPath.'/downloads/arsy-presensi.apk', 'apk-test-fixture');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('androidApkUrl', asset('downloads/arsy-presensi.apk')));
    }

    public function test_configured_apk_url_takes_precedence_over_the_local_file(): void
    {
        File::put($this->temporaryPublicPath.'/downloads/arsy-presensi.apk', 'apk-test-fixture');
        config()->set('mobile.android_apk_url', 'https://downloads.example.com/arsy.apk');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('androidApkUrl', 'https://downloads.example.com/arsy.apk'));
    }

    public function test_installation_page_displays_the_account_activation_message(): void
    {
        $this->withSession(['status' => 'Akun berhasil diaktifkan.'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('status', 'Akun berhasil diaktifkan.'));
    }
}
