<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Minishlink\WebPush\VAPID;
use Throwable;

class GenerateWebPushKeys extends Command
{
    protected $signature = 'notifications:vapid';

    protected $description = 'Generate missing Web Push VAPID keys and save them to .env';

    public function handle(): int
    {
        $path = app()->environmentFilePath();
        if (! File::exists($path)) {
            $this->error('File .env tidak ditemukan.');

            return self::FAILURE;
        }
        $contents = File::get($path);
        preg_match_all('/^WEBPUSH_VAPID_(?:PUBLIC|PRIVATE)_KEY=([^\r\n]*)/m', $contents, $existing);
        if (collect($existing[1])->contains(fn (string $value) => trim($value, " \t\"'") !== '')) {
            $this->error('Kunci VAPID sudah ada. Kunci tidak diubah agar subscription browser tetap berlaku.');

            return self::FAILURE;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable) {
            $this->error('Kunci VAPID tidak dapat dibuat. Pastikan OpenSSL tersedia dan OPENSSL_CONF menunjuk file konfigurasi yang valid sebelum menjalankan PHP.');

            return self::FAILURE;
        }
        foreach (['WEBPUSH_VAPID_PUBLIC_KEY' => $keys['publicKey'], 'WEBPUSH_VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $pattern = '/^'.preg_quote($name, '/').'=[^\r\n]*/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace($pattern, $name.'='.$value, $contents)
                : rtrim($contents).PHP_EOL.$name.'='.$value.PHP_EOL;
        }
        File::put($path, $contents);
        $this->info('Kunci VAPID disimpan ke .env. Jalankan php artisan config:clear jika konfigurasi di-cache.');

        return self::SUCCESS;
    }
}
