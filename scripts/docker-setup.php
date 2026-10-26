<?php

declare(strict_types=1);

// Standalone: works with the official PHP CLI image, without Composer/vendor.
$root = dirname(__DIR__);
$output = $root.'/.env.docker';

foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--output=')) {
        fwrite(STDERR, "Penggunaan: php scripts/docker-setup.php [--output=path]\n");
        exit(1);
    }

    $output = substr($argument, strlen('--output='));
}

if (file_exists($output)) {
    fwrite(STDOUT, "File konfigurasi sudah ada; kunci dan password tidak diubah.\n");
    exit(0);
}

try {
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    if ($key === false) {
        throw new RuntimeException('OpenSSL gagal membuat kunci VAPID. Periksa OPENSSL_CONF atau jalankan melalui image PHP Docker.');
    }

    $details = openssl_pkey_get_details($key);
    $ec = $details['ec'] ?? [];

    if (! isset($ec['x'], $ec['y'], $ec['d'])) {
        throw new RuntimeException('OpenSSL tidak mengembalikan komponen kunci EC.');
    }

    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $pad = static fn (string $value): string => str_pad($value, 32, "\0", STR_PAD_LEFT);
    $values = [
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'DB_PASSWORD' => bin2hex(random_bytes(32)),
        'MYSQL_ROOT_PASSWORD' => bin2hex(random_bytes(32)),
        'FACE_SERVICE_TOKEN' => bin2hex(random_bytes(32)),
        'REVERB_APP_ID' => bin2hex(random_bytes(8)),
        'REVERB_APP_KEY' => bin2hex(random_bytes(16)),
        'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
        'WEBPUSH_VAPID_PUBLIC_KEY' => $encode("\x04".$pad($ec['x']).$pad($ec['y'])),
        'WEBPUSH_VAPID_PRIVATE_KEY' => $encode($pad($ec['d'])),
    ];
    $template = file_get_contents($root.'/.env.docker.example');

    if ($template === false) {
        throw new RuntimeException('Template .env.docker.example tidak dapat dibaca.');
    }

    $contents = preg_replace_callback(
        '/^(APP_KEY|DB_PASSWORD|MYSQL_ROOT_PASSWORD|FACE_SERVICE_TOKEN|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET|WEBPUSH_VAPID_PUBLIC_KEY|WEBPUSH_VAPID_PRIVATE_KEY)=.*$/m',
        static fn (array $match): string => $match[1].'='.$values[$match[1]],
        $template,
    );

    umask(0077);
    $handle = fopen($output, 'x');

    if ($handle === false) {
        throw new RuntimeException('File konfigurasi tidak dapat dibuat; file yang ada tidak ditimpa.');
    }

    $written = fwrite($handle, $contents);
    fclose($handle);

    if ($written !== strlen($contents)) {
        throw new RuntimeException('Penulisan konfigurasi tidak lengkap. Periksa ruang disk.');
    }

    fwrite(STDOUT, "Konfigurasi Docker dibuat. Sesuaikan APP_URL dan domain sebelum production.\n");
    fwrite(STDOUT, "Simpan backup file ini; kunci dan password tidak ditampilkan.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
