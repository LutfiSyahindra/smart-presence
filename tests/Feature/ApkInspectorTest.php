<?php

namespace Tests\Feature;

use App\Services\Mobile\ApkInspector;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApkInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(config('mobile.aapt_path'))
            || ! is_file(config('mobile.apksigner_jar') ?: config('mobile.apksigner_path'))) {
            $this->markTestSkipped('APK integration tests require aapt, apksigner and Java (available in the project SDK or Docker image).');
        }
        // Synthetic test certificate. No production keystore is needed for these fixtures.
        config()->set('mobile.android_signing_sha256', 'b95616f18b00329ef54ec81143fe782d8221e650155d7c1a3e8d68c288b7c12c');
    }

    public function test_real_signed_apk_is_verified_and_manifest_metadata_is_read(): void
    {
        $metadata = app(ApkInspector::class)->inspect(base_path('tests/Fixtures/apk/valid.apk'));
        $this->assertSame('id.rsarsy.presensi', $metadata['package_name']);
        $this->assertSame(42, $metadata['version_code']);
        $this->assertSame('2.0', $metadata['version_name']);
        $this->assertSame(24, $metadata['min_sdk']);
    }

    public function test_real_unsigned_debug_and_other_package_apks_are_rejected(): void
    {
        foreach (['unsigned', 'debug', 'wrong-package'] as $name) {
            try {
                app(ApkInspector::class)->inspect(base_path('tests/Fixtures/apk/'.$name.'.apk'));
                $this->fail($name.' APK was accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('apk', $exception->errors());
            }
        }
    }

    public function test_real_apk_signed_by_an_untrusted_certificate_is_rejected(): void
    {
        config()->set('mobile.android_signing_sha256', str_repeat('a', 64));
        $this->expectException(ValidationException::class);
        app(ApkInspector::class)->inspect(base_path('tests/Fixtures/apk/valid.apk'));
    }

    public function test_tampering_with_signed_apk_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apk-signature-test-');
        try {
            $bytes = file_get_contents(base_path('tests/Fixtures/apk/valid.apk'));
            // Change signed manifest data while keeping the ZIP directory and signature block.
            $bytes[90] = chr(ord($bytes[90]) ^ 1);
            file_put_contents($path, $bytes);
            $this->expectException(ValidationException::class);
            app(ApkInspector::class)->inspect($path);
        } finally {
            unlink($path);
        }
    }
}
