<?php

namespace App\Services\Mobile;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class ApkInspector
{
    /** @return array{package_name: string, version_code: int, version_name: string, min_sdk: int, certificate_sha256: string} */
    public function inspect(string $path): array
    {
        $badging = $this->run([(string) config('mobile.aapt_path'), 'dump', 'badging', $path]);
        $signer = config('mobile.apksigner_jar')
            ? [(string) config('mobile.java_path'), '-jar', (string) config('mobile.apksigner_jar')]
            : [(string) config('mobile.apksigner_path')];
        $signature = $this->run([...$signer, 'verify', '--print-certs', $path]);

        preg_match("/^package: name='([^']+)' versionCode='([0-9]+)' versionName='([^']+)'/m", $badging, $package);
        preg_match("/^sdkVersion:'([0-9]+)'/m", $badging, $sdk);
        preg_match_all('/^Signer #[0-9]+ certificate SHA-256 digest: ([a-fA-F0-9]{64})\r?$/m', $signature, $certificates);
        $expected = strtolower(str_replace(':', '', trim((string) config('mobile.android_signing_sha256'))));

        if (! $package || ! $sdk || count($certificates[1]) !== 1
            || $package[1] !== config('mobile.android_package')
            || ! preg_match('/^[a-f0-9]{64}$/', $expected)
            || ! hash_equals($expected, strtolower($certificates[1][0]))) {
            throw ValidationException::withMessages(['apk' => 'APK harus merupakan aplikasi RSARSY dengan sertifikat penandatanganan produksi yang sesuai.']);
        }
        if ((int) $package[2] < 1 || (int) $package[2] > 2100000000 || strlen($package[3]) > 100
            || str_contains($badging, 'application-debuggable') || preg_match("/\bsplit='[^']+'/", $badging)) {
            throw ValidationException::withMessages(['apk' => 'Gunakan APK release lengkap dengan versi yang valid, bukan APK debug atau split APK.']);
        }

        return [
            'package_name' => $package[1], 'version_code' => (int) $package[2],
            'version_name' => $package[3], 'min_sdk' => (int) $sdk[1],
            'certificate_sha256' => strtolower($certificates[1][0]),
        ];
    }

    private function run(array $command): string
    {
        try {
            // Arguments are passed separately; filenames never become shell commands.
            $process = new Process($command, timeout: 30);
            $process->run();
            if ($process->isSuccessful()) {
                return $process->getOutput();
            }
        } catch (ExceptionInterface $exception) {
            report($exception);
        }

        throw ValidationException::withMessages([
            'apk' => 'APK tidak dapat diverifikasi. Pastikan file APK utuh dan alat aapt, apksigner, serta Java tersedia pada server.',
        ]);
    }
}
