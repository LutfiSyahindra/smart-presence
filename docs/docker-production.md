# Docker production

Stack ini menjalankan Nginx, PHP-FPM 8.3, MySQL 8.4, queue worker,
scheduler Laravel, Reverb untuk sinkronisasi mobile, dan FastAPI/DeepFace. Vue dibangun di image memakai Node;
tidak ada Vite development server atau container Redis.

Verifikasi absensi menggunakan queue khusus dengan dua worker; push pengajuan
memakai worker terpisah. Alur, konfigurasi, dan deployment perubahan ini ada pada
[antrean absensi](attendance-queue.md).

## 1. Buat konfigurasi

Gunakan Docker Engine/Desktop dengan **Linux containers** dan Docker Compose
v2 atau lebih baru. Jalankan semua perintah dari root repository.

Jika PHP CLI tersedia:

```sh
php scripts/docker-setup.php
```

Jika server hanya memiliki Docker, perintah berikut dapat digunakan pada
Bash maupun PowerShell:

```sh
docker run --rm -v "${PWD}:/workspace" -w /workspace php:8.3-cli php scripts/docker-setup.php
```

Script membuat `.env.docker` dari `.env.docker.example`, termasuk `APP_KEY`,
password MySQL, token layanan wajah, credential Reverb, dan pasangan kunci VAPID acak.
File yang sudah ada tidak ditimpa. `.env` lokal tidak diubah dan tidak ikut
masuk image; `.env.docker` juga diabaikan Git. Simpan backup `.env.docker`.

Jika memperbarui instalasi yang sudah memiliki `.env.docker`, jalankan
`php scripts/realtime-setup.php --env-file=.env.docker --docker` sebelum rebuild.
Panduan endpoint dan pengujian tersedia pada [sinkronisasi mobile](mobile-realtime.md).

Pada PHP portable Windows, jika OpenSSL tidak dapat membuat kunci, atur
`OPENSSL_CONF` ke file `extras/ssl/openssl.cnf` milik instalasi PHP, atau gunakan
perintah Docker di atas.

Default dapat dicoba di `http://localhost:8080`. Untuk domain production,
sesuaikan setidaknya:

```dotenv
APP_URL=https://presensi.domain-anda.id
ACCOUNT_ACTIVATION_URL="${APP_URL}"
NGINX_HOST=presensi.domain-anda.id
APP_HTTPS=on
SESSION_SECURE_COOKIE=true
WEBPUSH_VAPID_SUBJECT=mailto:admin@domain-anda.id
MAIL_FROM_ADDRESS=admin@domain-anda.id
```

`ACCOUNT_ACTIVATION_URL` adalah URL dasar untuk tombol aktivasi akun pegawai
di email. Default mengikuti `APP_URL`; jika perlu, isi langsung dengan domain
publik yang membuka aplikasi ini, misalnya `https://presensi.domain-anda.id`.
Jangan menambahkan `/reset-password`, token, atau query email karena aplikasi
menambahkannya otomatis. Jika kosong, aplikasi memakai `APP_URL`.
Untuk instalasi tanpa Docker, atur variabel yang sama di `.env`, lalu jalankan
`php artisan config:clear`. Untuk Docker, ubah `.env.docker` lalu recreate
container Laravel dengan perintah berikut agar environment terbaru digunakan:

```sh
docker compose --env-file .env.docker up -d --force-recreate app queue queue-attendance queue-attendance-2 scheduler
```

Jika memakai HTTPS langsung pada Nginx Docker, tambahkan pasangan
`-f compose.yml -f compose.https.yml` seperti dijelaskan di bawah.

Atur `MOBILE_CORS_ALLOWED_ORIGINS` sesuai origin aplikasi mobile. Jika memakai
email, isi pengaturan SMTP; default `MAIL_MAILER=log` hanya menulis ke log.

**Jika memindahkan aplikasi yang sudah berisi data**, salin `APP_KEY` serta
kunci VAPID lama ke `.env.docker` sebelum startup. Salin pula pengaturan SMTP,
push, pembatasan jaringan, dan layanan wajah yang sebelumnya digunakan.
Mengganti `APP_KEY` dapat membuat data terenkripsi lama tidak dapat dibaca;
mengganti VAPID memutus subscription push lama.

## 2. Jalankan

Untuk mencoba HTTP di localhost, atau jika HTTPS ditangani reverse proxy server:

```sh
docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker ps -a
```

Nama dan port internal sudah disetel oleh Compose: Laravel menghubungi
`mysql:3306` dan `face-verification:8001`. Hanya Nginx yang mempublikasikan port.
Default HTTP terikat ke `127.0.0.1:8080`; MySQL, PHP-FPM, dan FastAPI tidak
dipublikasikan ke host.

Service `init` menunggu MySQL sehat, menyiapkan izin volume, lalu menjalankan
`php artisan migrate --force` dan cache view. App, queue, dan scheduler baru
dimulai setelah `init` berhasil. Status `init` **Exited (0)** adalah normal.
Setiap container Laravel menyimpan cache konfigurasi dan route masing-masing.
Worker memakai timeout 60 detik, di bawah `retry_after` 90 detik.

Startup pertama DeepFace mengunduh model dan dapat memakan waktu beberapa
menit. Halaman web dapat digunakan ketika layanan wajah masih memuat model;
presensi dengan verifikasi wajah memerlukan layanan itu siap.

```sh
docker compose --env-file .env.docker logs -f init app web queue queue-attendance queue-attendance-2 scheduler face-verification
docker compose --env-file .env.docker exec web wget -qO- http://127.0.0.1/up
docker compose --env-file .env.docker exec face-verification python -c "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8001/api/v1/health/ready').read().decode())"
```

### HTTPS langsung pada Nginx Docker

Buat direktori `docker/nginx/certs`, lalu letakkan sertifikat valid untuk domain:

```text
docker/nginx/certs/fullchain.pem
docker/nginx/certs/privkey.pem
```

Direktori sertifikat diabaikan Git dan build context. Atur nilai domain di
bagian pertama, lalu untuk HTTP port 80 dan HTTPS port 443 yang dapat diakses
dari luar, ubah `.env.docker`:

```dotenv
APP_BIND_ADDRESS=0.0.0.0
APP_PORT=80
HTTPS_BIND_ADDRESS=0.0.0.0
HTTPS_PORT=443
```

Jalankan dengan **kedua file Compose**:

```sh
docker compose --env-file .env.docker -f compose.yml -f compose.https.yml config --quiet
docker compose --env-file .env.docker -f compose.yml -f compose.https.yml up -d --build
```

HTTP akan diarahkan ke HTTPS. Pakai pasangan `-f` yang sama untuk operasi
deployment berikutnya. Sertifikat tidak diterbitkan atau diperpanjang otomatis;
kelola melalui penyedia sertifikat/ACME server. Setelah memperbarui file:

```sh
docker compose --env-file .env.docker -f compose.yml -f compose.https.yml exec web nginx -t
docker compose --env-file .env.docker -f compose.yml -f compose.https.yml exec web nginx -s reload
```

### HTTPS melalui reverse proxy di host

Gunakan `compose.yml` saja, pertahankan binding `127.0.0.1:8080`, lalu arahkan
reverse proxy HTTPS ke `http://127.0.0.1:8080`. `APP_HTTPS=on` membuat Laravel
mengenali request sebagai HTTPS tanpa mempercayai header skema dari publik.
Proxy harus meneruskan `Host` domain asli dan `X-Forwarded-For`, serta mendukung
WebSocket upgrade (`Upgrade`/`Connection`) untuk `/app/`. Reverb diakses melalui
origin HTTPS aplikasi yang sama; port internalnya tidak perlu dipublikasikan.

Jika fitur presensi membatasi IP jaringan, isi `NGINX_TRUSTED_PROXY_CIDR`
dengan **IP proxy yang benar-benar terlihat oleh Nginx** (pada Docker Linux,
sering berupa gateway bridge), agar IP klien asli dapat dipulihkan. Jangan
mengisinya dengan `0.0.0.0/0` atau memasukkan subnet Docker sebagai jaringan
pegawai. Verifikasi IP yang terlihat aplikasi sebelum mengaktifkan
`ATTENDANCE_REQUIRE_TRUSTED_NETWORK=true` dan mengisi
`ATTENDANCE_TRUSTED_NETWORKS` dengan IP/CIDR rumah sakit.

## 3. Data awal dan akun admin

Deployment membuat schema, tanpa menjalankan `DatabaseSeeder`. Seeder bawaan
project membuat akun dengan password default sehingga tidak dijalankan otomatis.

Untuk database baru, isi role/permission dan master unit/jabatan:

```sh
docker compose --env-file .env.docker exec app php artisan db:seed --class=AuthorizationSeeder --force
docker compose --env-file .env.docker exec app php artisan db:seed --class=UnitJabatanSeeder --force
```

Daftarkan akun milik administrator melalui `/register` dengan password yang kuat.
Kemudian buka Tinker:

```sh
docker compose --env-file .env.docker exec app php artisan tinker
```

Jalankan, menggunakan email akun yang baru dibuat:

```php
App\Models\User::where('email', 'admin@domain-anda.id')->firstOrFail()->assignRole('super-admin');
```

Admin dapat masuk di `/absensi/admin-web/login`. Untuk database lama, import
backup dan gunakan akun yang sudah ada; jangan menjalankan seeder data awal.
Kunci VAPID sudah tersedia dari setup; tidak perlu menjalankan
`notifications:vapid` di container karena konfigurasi diinjeksi sebagai environment.

## 4. Penyimpanan dan pemindahan data

Tiga named volume bertahan saat container dihapus atau dibangun ulang:

| Volume | Isi |
| --- | --- |
| `mysql-data` | Database MySQL |
| `app-storage` | Foto profil, foto presensi, lampiran, session file dan cache runtime |
| `face-verification-models` | Model DeepFace yang sudah diunduh |

Nama fisik volume diberi prefix `COMPOSE_PROJECT_NAME`; pertahankan nilainya
agar deployment selanjutnya memakai data yang sama. `bootstrap/cache` berada
di filesystem container agar konfigurasi tidak tertinggal lintas image.

Jika memindahkan instalasi lama, backup database dan storage lebih dulu.
Sebelum startup pertama, import SQL ke MySQL lalu salin isi direktori storage
lama ke volume, atau setelah startup jalankan pada waktu pemeliharaan:

```sh
docker compose --env-file .env.docker stop app web queue queue-attendance queue-attendance-2 scheduler
docker compose --env-file .env.docker cp ./storage/. init:/var/www/html/storage
docker compose --env-file .env.docker run --rm init
docker compose --env-file .env.docker up -d
```

Perintah `run --rm init` memperbaiki kepemilikan file dan menjalankan migrasi.
`docker compose cp` juga bisa memakai container `init` yang sudah berhenti.
Untuk SQL, gunakan `mysql` dari container; contoh Bash pada server Linux:

```sh
docker compose --env-file .env.docker exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' < backup.sql
```

Credential FCM/APNs dapat disalin setelah volume terbentuk:

```sh
docker compose --env-file .env.docker exec app mkdir -p storage/app/private/push
docker compose --env-file .env.docker cp ./firebase-service-account.json init:/var/www/html/storage/app/private/push/firebase.json
```

Kemudian atur
`FCM_CREDENTIALS=/var/www/html/storage/app/private/push/firebase.json` di
`.env.docker`. Jalankan ulang `init` agar izin file benar, dan recreate app,
queue, serta scheduler setelah perubahan environment. Jangan menyimpan
credential tersebut di repository atau build context.

## 5. Deployment berikutnya dan backup

Untuk menghindari worker atau request lama berjalan ketika schema berubah,
lakukan deployment pada waktu pemeliharaan setelah backup:

```sh
docker compose --env-file .env.docker build
docker compose --env-file .env.docker stop web app queue queue-attendance queue-attendance-2 scheduler
docker compose --env-file .env.docker up -d --force-recreate init
docker compose --env-file .env.docker wait init
docker compose --env-file .env.docker up -d
```

Pastikan `init` keluar dengan kode 0 sebelum menjalankan perintah terakhir.
Jika migrasi gagal, baca `logs init` dan selesaikan kegagalannya sebelum
menyalakan aplikasi. Untuk deployment HTTPS, sertakan kedua `-f` pada semua
perintah. Restart biasa cukup memakai `restart`; perubahan environment
memerlukan `up -d --force-recreate` agar cache konfigurasi dibangun kembali.

Contoh backup database pada Bash/Linux:

```sh
docker compose --env-file .env.docker exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --no-tablespaces --single-transaction -u"$MYSQL_USER" "$MYSQL_DATABASE"' > backup.sql
```

Backup juga volume `app-storage` dan `.env.docker`, lalu uji pemulihannya.
Pada Windows, hindari redirect backup SQL biner melalui Windows PowerShell 5;
gunakan Bash/WSL atau tulis backup ke file di container lalu `docker compose cp`.

`docker compose down` mempertahankan volume. **`down -v` menghapus database,
foto/upload, dan model yang tersimpan di named volume.**

Referensi: [urutan startup Compose](https://docs.docker.com/compose/how-tos/startup-order/),
[deployment Laravel](https://laravel.com/docs/12.x/deployment), dan
[IP asli pada Nginx](https://nginx.org/en/docs/http/ngx_http_realip_module.html).
