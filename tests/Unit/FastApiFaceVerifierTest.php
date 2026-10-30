<?php

namespace Tests\Unit;

use App\Exceptions\FaceVerificationException;
use App\Services\Absensi\AbsensiMobile\FastApiFaceVerifier;
use Illuminate\Http\Client\Factory;
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

    public function test_it_rejects_a_model_match_below_the_minimum_similarity(): void
    {
        Http::fake([
            '*' => Http::response([
                'verified' => true,
                'similarity' => 39.99,
                'provider' => 'deepface-facenet512',
            ]),
        ]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertFalse($result->matched);
        $this->assertSame(39.99, $result->similarity);
    }

    public function test_it_accepts_a_model_match_at_the_minimum_similarity(): void
    {
        Http::fake([
            '*' => Http::response([
                'verified' => true,
                'similarity' => 40.0,
                'provider' => 'deepface-facenet512',
            ]),
        ]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertTrue($result->matched);
        $this->assertSame(40.0, $result->similarity);
    }

    public function test_it_accepts_a_service_match_above_the_minimum(): void
    {
        Http::fake(['*' => Http::response(['verified' => true, 'similarity' => 63.0])]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertTrue($result->matched);
    }

    public function test_it_respects_a_service_rejection_above_the_minimum(): void
    {
        Http::fake(['*' => Http::response(['verified' => false, 'similarity' => 63.0])]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertFalse($result->matched);
    }

    public function test_it_respects_an_explicitly_configured_minimum(): void
    {
        Http::fake(['*' => Http::response(['verified' => true, 'similarity' => 63.0])]);
        $verifier = new FastApiFaceVerifier('http://127.0.0.1:8001', self::TOKEN, minimumSimilarity: 70.0);

        $result = $verifier->compare($this->jpeg(), $this->jpeg());

        $this->assertFalse($result->matched);
    }

    public function test_it_fails_closed_when_a_model_match_has_no_similarity(): void
    {
        Http::fake([
            '*' => Http::response([
                'verified' => true,
                'similarity' => null,
                'provider' => 'deepface-facenet512',
            ]),
        ]);

        $result = $this->verifier()->compare($this->jpeg(), $this->jpeg());

        $this->assertFalse($result->matched);
        $this->assertNull($result->similarity);
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

    public function test_a_model_match_between_40_and_the_previous_minimum_is_accepted(): void
    {
        Http::fake(['*' => Http::response(['verified' => true, 'similarity' => 49.0])]);
        $verifier = new FastApiFaceVerifier('http://127.0.0.1:8001', self::TOKEN, minimumSimilarity: 40.0);

        $this->assertTrue($verifier->compare($this->jpeg(), $this->jpeg())->matched);
    }

    public function test_it_validates_a_profile_photo_with_an_authenticated_request(): void
    {
        Http::fake(['*' => Http::response(['valid' => true])]);

        $this->verifier()->validateProfilePhoto($this->jpeg());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8001/api/v1/profile/validate'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasFile('profile_photo')
            && ! $request->hasFile('clock_in_photo'));
    }

    public function test_it_rejects_a_profile_that_fails_the_quality_check(): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'code' => 'invalid_profile_photo', 'message' => 'Foto wajah terlalu buram.',
        ]], 422)]);

        $this->expectExceptionObject(new FaceVerificationException(
            FaceVerificationException::INVALID_PROFILE_PHOTO, 'Foto wajah terlalu buram.',
        ));

        $this->verifier()->validateProfilePhoto($this->jpeg());
    }

    public function test_profile_validation_fails_closed_on_an_invalid_or_unavailable_response(): void
    {
        foreach ([['valid' => false], ['valid' => 'true'], ['verified' => true], null] as $payload) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($payload, $payload === null ? 503 : 200)]);

            try {
                $this->verifier()->validateProfilePhoto($this->jpeg());
                $this->fail('The profile must not be saved without a valid quality check.');
            } catch (FaceVerificationException $exception) {
                $this->assertSame(FaceVerificationException::SERVICE_UNAVAILABLE, $exception->reason);
            }
        }
    }

    public function test_profile_validation_fails_closed_on_a_connection_error(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->expectException(FaceVerificationException::class);
        $this->verifier()->validateProfilePhoto($this->jpeg());
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
