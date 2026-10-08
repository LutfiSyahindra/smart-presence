<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\ValueObjects\VerifiedAttendanceLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

class AttendanceLocationVerifier
{
    /**
     * @return array{token: string, min_samples: int, collection_seconds: int, max_accuracy_meters: float, max_speed_meters_per_second: float}
     */
    public function issueChallenge(Request $request, string $action): array
    {
        $token = Str::random(64);
        $challenge = [
            'token_hash' => hash('sha256', $token),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'issued_at' => now()->getTimestampMs(),
        ];

        if ($request->hasSession()) {
            $request->session()->put($this->sessionKey($action), $challenge);
        } else {
            Cache::put(
                $this->cacheKey($action, $token),
                $challenge,
                now()->addMilliseconds($this->proofTtlMilliseconds()),
            );
        }

        return [
            'token' => $token,
            'min_samples' => $this->minSamples(),
            'collection_seconds' => $this->collectionSeconds(),
            'max_accuracy_meters' => $this->maxAccuracy(),
            'max_speed_meters_per_second' => $this->maxSpeed(),
        ];
    }

    public function verify(Request $request, string $action): VerifiedAttendanceLocation
    {
        $this->verifyTrustedNetwork($request);

        $token = (string) $request->input('location_token', '');
        $challenge = $request->hasSession()
            ? $request->session()->get($this->sessionKey($action))
            : Cache::get($this->cacheKey($action, $token));
        $now = now()->getTimestampMs();

        if (
            ! is_array($challenge)
            || ! isset($challenge['token_hash'], $challenge['user_id'], $challenge['issued_at'])
            || ! hash_equals((string) $challenge['token_hash'], hash('sha256', $token))
            || (string) $challenge['user_id'] !== (string) $request->user()?->getAuthIdentifier()
            || $now - (int) $challenge['issued_at'] > $this->proofTtlMilliseconds()
            || (int) $challenge['issued_at'] > $now + 30_000
        ) {
            $this->fail('Sesi verifikasi lokasi sudah tidak valid. Muat ulang halaman lalu ambil lokasi kembali.');
        }

        /** @var list<array{latitude: float|int|string, longitude: float|int|string, accuracy: float|int|string, captured_at: int|string}> $samples */
        $samples = $request->input('location_samples', []);
        // Inspect every submitted fix before accuracy filtering. A mocked fix
        // must also be rejected when other, more accurate fixes look legitimate.
        foreach ($samples as $index => $sample) {
            if ($request->boolean("location_samples.{$index}.is_mock")) {
                $this->fail('Fake GPS terdeteksi. Nonaktifkan aplikasi lokasi palsu lalu ambil lokasi kembali.');
            }
            if ($request->input('location_source') === 'android_native' && ! array_key_exists('is_mock', $sample)) {
                $this->fail('Pemeriksaan keaslian GPS Android belum tersedia. Perbarui aplikasi lalu ambil lokasi kembali.');
            }
        }
        $samples = array_map(static fn (array $sample): array => [
            'latitude' => (float) $sample['latitude'],
            'longitude' => (float) $sample['longitude'],
            'accuracy' => (float) $sample['accuracy'],
            'captured_at' => (int) $sample['captured_at'],
        ], $samples);

        $accurateSamples = array_values(array_filter(
            $samples,
            fn (array $sample): bool => $sample['accuracy'] <= $this->maxAccuracy(),
        ));

        if (count($accurateSamples) < $this->minSamples()) {
            $this->fail('Akurasi GPS belum cukup baik. Aktifkan mode lokasi akurasi tinggi dan coba kembali di area terbuka.');
        }

        $previous = null;
        foreach ($accurateSamples as $sample) {
            if (
                $sample['captured_at'] < (int) $challenge['issued_at'] - 15_000
                || $sample['captured_at'] > $now + 30_000
            ) {
                $this->fail('Sampel GPS tidak segar. Ambil lokasi kembali sebelum melakukan presensi.');
            }

            if ($previous !== null) {
                $elapsedSeconds = ($sample['captured_at'] - $previous['captured_at']) / 1000;
                if ($elapsedSeconds <= 0) {
                    $this->fail('Urutan sampel GPS tidak valid. Ambil lokasi kembali.');
                }

                $speed = $this->distanceInMeters(
                    $previous['latitude'],
                    $previous['longitude'],
                    $sample['latitude'],
                    $sample['longitude'],
                ) / $elapsedSeconds;

                if ($speed > $this->maxSpeed()) {
                    $this->fail('Terdeteksi lonjakan lokasi yang tidak wajar. Nonaktifkan aplikasi pengubah lokasi lalu coba kembali.');
                }
            }

            $previous = $sample;
        }

        $firstSample = $accurateSamples[0];
        $lastSample = $accurateSamples[array_key_last($accurateSamples)];
        if ($this->collectionSeconds() * 1000 > $lastSample['captured_at'] - $firstSample['captured_at']) {
            $this->fail('GPS perlu diverifikasi selama beberapa detik. Ambil lokasi kembali dan tunggu sampai proses selesai.');
        }
        if ($now - $lastSample['captured_at'] > $this->sampleFreshnessMilliseconds()) {
            $this->fail('Lokasi sudah terlalu lama. Ambil lokasi kembali sebelum melakukan presensi.');
        }

        $latitudes = array_column($accurateSamples, 'latitude');
        $longitudes = array_column($accurateSamples, 'longitude');
        sort($latitudes, SORT_NUMERIC);
        sort($longitudes, SORT_NUMERIC);

        return new VerifiedAttendanceLocation(
            latitude: $this->median($latitudes),
            longitude: $this->median($longitudes),
            accuracy: max(array_column($accurateSamples, 'accuracy')),
            sampleCount: count($accurateSamples),
        );
    }

    public function consumeChallenge(Request $request, string $action): void
    {
        if ($request->hasSession()) {
            $request->session()->forget($this->sessionKey($action));

            return;
        }

        Cache::forget($this->cacheKey($action, (string) $request->input('location_token', '')));
    }

    private function verifyTrustedNetwork(Request $request): void
    {
        if (! config('attendance.location.require_trusted_network', false)) {
            return;
        }

        $trustedNetworks = config('attendance.location.trusted_networks', []);
        if (! is_array($trustedNetworks) || $trustedNetworks === []) {
            $this->fail('Jaringan presensi belum dikonfigurasi. Hubungi administrator.');
        }

        if (! IpUtils::checkIp((string) $request->ip(), $trustedNetworks)) {
            $this->fail('Presensi hanya dapat dilakukan melalui jaringan rumah sakit yang tepercaya.');
        }
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function distanceInMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $originLatitude = deg2rad($fromLatitude);
        $destinationLatitude = deg2rad($toLatitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($originLatitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;
        $a = min(1, max(0, $a));

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private function sessionKey(string $action): string
    {
        return "attendance.location_challenges.{$action}";
    }

    private function cacheKey(string $action, string $token): string
    {
        return 'attendance:location-challenge:'.$action.':'.hash('sha256', $token);
    }

    private function minSamples(): int
    {
        return max(2, (int) config('attendance.location.min_samples', 3));
    }

    private function collectionSeconds(): int
    {
        return max(2, (int) config('attendance.location.collection_seconds', 3));
    }

    private function maxAccuracy(): float
    {
        return max(10, (float) config('attendance.location.max_accuracy_meters', 100));
    }

    private function maxSpeed(): float
    {
        return max(20, (float) config('attendance.location.max_speed_meters_per_second', 80));
    }

    private function proofTtlMilliseconds(): int
    {
        return max(60, (int) config('attendance.location.proof_ttl_seconds', 600)) * 1000;
    }

    private function sampleFreshnessMilliseconds(): int
    {
        return max(30, (int) config('attendance.location.sample_freshness_seconds', 300)) * 1000;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['location_proof' => $message]);
    }
}
