<?php

namespace Tests\Unit;

use App\Exceptions\FaceVerificationException;
use App\Services\Absensi\AbsensiMobile\FastApiFaceVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FastApiFaceVerifierTest extends TestCase
{
    private const TOKEN = 'test-token-with-at-least-32-characters';

    public function test_it_returns_a_successful_verification_result(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/verify' => Http::response([
                'verified' => true,
                'similarity' => 97.81,
                'provider' => 'deepface-facenet512',
            ]),
        ]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertTrue($result->matched);
        $this->assertSame(97.81, $result->similarity);
        $this->assertSame('deepface-facenet512', $result->provider);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8001/api/v1/verify'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasFile('profile_photo')
                && $request->hasFile('clock_in_photo');
        });
    }

    public function test_it_maps_a_spoof_response_to_a_domain_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 'spoof_detected',
                    'message' => 'Spoof terdeteksi.',
                ],
            ], 422),
        ]);

        $this->expectExceptionObject(new FaceVerificationException(
            FaceVerificationException::SPOOF_DETECTED,
            'Spoof terdeteksi.',
        ));

        $this->verifier()->compare($this->jpeg(), $this->jpeg());
    }

    public function test_it_fails_closed_when_the_service_returns_an_invalid_payload(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        try {
            $this->verifier()->compare($this->jpeg(), $this->jpeg());
            $this->fail('An invalid service response must fail closed.');
        } catch (FaceVerificationException $exception) {
            $this->assertSame(FaceVerificationException::SERVICE_UNAVAILABLE, $exception->reason);
        }
    }

    public function test_it_fails_closed_when_the_service_token_is_not_configured(): void
    {
        $verifier = new FastApiFaceVerifier('http://127.0.0.1:8001', '');

        try {
            $verifier->compare($this->jpeg(), $this->jpeg());
            $this->fail('A missing service token must fail closed.');
        } catch (FaceVerificationException $exception) {
            $this->assertSame(FaceVerificationException::SERVICE_UNAVAILABLE, $exception->reason);
        }

        Http::assertNothingSent();
    }

    private function verifier(): FastApiFaceVerifier
    {
        return new FastApiFaceVerifier('http://127.0.0.1:8001', self::TOKEN);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(600, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 230, 230));
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();
        imagedestroy($image);

        return (string) $contents;
    }
}
