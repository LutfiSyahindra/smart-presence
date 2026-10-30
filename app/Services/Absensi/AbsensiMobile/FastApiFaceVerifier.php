<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Contracts\ProfilePhotoValidator;
use App\Exceptions\FaceVerificationException;
use App\ValueObjects\FaceVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class FastApiFaceVerifier implements FaceVerifier, ProfilePhotoValidator
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $connectTimeout = 3,
        private readonly int $timeout = 45,
        private readonly float $minimumSimilarity = 51.0,
    ) {}

    public function compare(string $profilePhoto, string $clockInPhoto): FaceVerificationResult
    {
        try {
            $response = $this->client()
                ->attach('profile_photo', $profilePhoto, 'profile-photo', [
                    'Content-Type' => $this->mimeType($profilePhoto),
                ])
                ->attach('clock_in_photo', $clockInPhoto, 'clock-in-photo', [
                    'Content-Type' => $this->mimeType($clockInPhoto),
                ])
                ->post('/api/v1/verify');
        } catch (ConnectionException $exception) {
            throw $this->unavailable($exception);
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }

        if ($response->failed()) {
            throw $this->exceptionFrom($response);
        }

        $payload = $response->json();
        if (! is_array($payload) || ! array_key_exists('verified', $payload) || ! is_bool($payload['verified'])) {
            throw $this->unavailable();
        }

        $similarity = $payload['similarity'] ?? null;
        if ($similarity !== null && ! is_numeric($similarity)) {
            throw $this->unavailable();
        }

        $normalizedSimilarity = $similarity === null
            ? null
            : max(0, min(100, (float) $similarity));

        $provider = $payload['provider'] ?? 'deepface-fastapi';
        if (! is_string($provider) || trim($provider) === '') {
            $provider = 'deepface-fastapi';
        }

        return new FaceVerificationResult(
            matched: $payload['verified']
                && $normalizedSimilarity !== null
                && $normalizedSimilarity >= max(51, $this->minimumSimilarity),
            similarity: $normalizedSimilarity,
            provider: mb_substr($provider, 0, 50),
        );
    }

    public function validateProfilePhoto(string $photo): void
    {
        try {
            $response = $this->client()
                ->attach('profile_photo', $photo, 'profile-photo', [
                    'Content-Type' => $this->mimeType($photo),
                ])
                ->post('/api/v1/profile/validate');
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }

        if ($response->failed()) {
            throw $this->exceptionFrom($response);
        }

        if ($response->json('valid') !== true) {
            throw $this->unavailable();
        }
    }

    private function client(): PendingRequest
    {
        if (trim($this->baseUrl) === '' || strlen($this->token) < 32) {
            throw $this->unavailable();
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->withToken($this->token)
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }

    private function exceptionFrom(Response $response): FaceVerificationException
    {
        $reason = $response->json('error.code');
        $message = $response->json('error.message');

        if (! is_string($reason)) {
            return $this->unavailable();
        }

        return match ($reason) {
            FaceVerificationException::INVALID_PROFILE_PHOTO => new FaceVerificationException(
                FaceVerificationException::INVALID_PROFILE_PHOTO,
                is_string($message) ? $message : 'Foto profil tidak dapat digunakan.',
            ),
            FaceVerificationException::FACE_NOT_FOUND => new FaceVerificationException(
                FaceVerificationException::FACE_NOT_FOUND,
                is_string($message) ? $message : 'Wajah tidak ditemukan pada foto clock-in.',
            ),
            FaceVerificationException::MULTIPLE_FACES => new FaceVerificationException(
                FaceVerificationException::MULTIPLE_FACES,
                is_string($message) ? $message : 'Foto clock-in memuat lebih dari satu wajah.',
            ),
            FaceVerificationException::SPOOF_DETECTED => new FaceVerificationException(
                FaceVerificationException::SPOOF_DETECTED,
                is_string($message) ? $message : 'Foto clock-in tidak lolos pemeriksaan anti-spoofing.',
            ),
            default => $this->unavailable(),
        };
    }

    private function unavailable(?Throwable $previous = null): FaceVerificationException
    {
        return new FaceVerificationException(
            FaceVerificationException::SERVICE_UNAVAILABLE,
            'Layanan verifikasi wajah sedang tidak tersedia.',
            $previous,
        );
    }

    private function mimeType(string $contents): string
    {
        $info = @getimagesizefromstring($contents);

        return $info && isset($info['mime']) && is_string($info['mime'])
            ? $info['mime']
            : 'application/octet-stream';
    }
}
