<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root.'/.env';
$docker = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--docker') {
        $docker = true;
    } elseif (str_starts_with($argument, '--env-file=')) {
        $file = substr($argument, 11);
    } else {
        fwrite(STDERR, "Penggunaan: php scripts/realtime-setup.php [--env-file=.env.docker --docker]\n");
        exit(1);
    }
}
if (! is_file($file)) {
    fwrite(STDERR, "File konfigurasi belum ada. Siapkan .env atau .env.docker terlebih dahulu.\n");
    exit(1);
}
$contents = file_get_contents($file);
preg_match('/^APP_URL=(.*)$/m', $contents, $appUrl);
$appHost = parse_url(trim($appUrl[1] ?? 'http://localhost', " \r\n\t\"'"), PHP_URL_HOST) ?: 'localhost';
$values = [
    'BROADCAST_CONNECTION' => 'reverb', 'MOBILE_REALTIME_ENABLED' => 'true',
    'REVERB_APP_ID' => bin2hex(random_bytes(8)), 'REVERB_APP_KEY' => bin2hex(random_bytes(16)),
    'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
    'REVERB_HOST' => $docker ? 'reverb' : '127.0.0.1', 'REVERB_PORT' => '8080', 'REVERB_SCHEME' => 'http',
    'REVERB_SERVER_HOST' => $docker ? '0.0.0.0' : '127.0.0.1', 'REVERB_SERVER_PORT' => '8080',
    'MOBILE_REALTIME_PORT' => $docker ? '' : '8080',
    'REVERB_ALLOWED_ORIGINS' => $docker ? '' : implode(',', array_unique([$appHost, 'localhost', '127.0.0.1'])),
];
foreach ($values as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';
    if (preg_match($pattern, $contents, $match)) {
        // Keep credentials and custom endpoints on repeated setup runs.
        if (! in_array($key, ['BROADCAST_CONNECTION', 'MOBILE_REALTIME_ENABLED'], true) && trim($match[1], " \r\n\t\"'") !== '') {
            continue;
        }
        $contents = preg_replace($pattern, $key.'='.$value, $contents);
    } else {
        $contents = rtrim($contents)."\n{$key}={$value}\n";
    }
}
if (file_put_contents($file, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Konfigurasi realtime gagal disimpan.\n");
    exit(1);
}
fwrite(STDOUT, "Realtime diaktifkan; credential yang sudah ada tetap digunakan dan tidak ditampilkan.\n");
