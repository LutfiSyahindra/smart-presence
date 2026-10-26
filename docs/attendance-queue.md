# Antrean verifikasi absensi

Clock-in dan clock-out menerima permintaan terlebih dahulu, lalu memverifikasi
wajah melalui queue `attendance`. GPS, jaringan, profil pegawai, cuti, hari
libur, dan kelengkapan foto diperiksa saat pengiriman. Waktu server pada saat
itu disimpan sebagai `received_at` dan menjadi jam presensi setelah verifikasi
berhasil, termasuk bila worker baru selesai pada hari berikutnya.

## Perilaku aplikasi

- Permintaan tersimpan pada tabel `attendance_submissions`; tabel `presensis`
  hanya berubah setelah wajah berhasil diverifikasi.
- Status adalah `pending`, `processing`, `succeeded`, atau `failed`. Mobile/PWA
  membuka halaman status, dan beranda menyediakan tautan untuk melanjutkan.
- Halaman status memperbarui hasil setiap 3 detik saat terlihat. Gangguan koneksi
  memperlambat pemeriksaan menjadi 10 detik; halaman tersembunyi berhenti polling.
- Setelah verifikasi berhasil, halaman status otomatis membuka ringkasan clock-in
  atau clock-out seperti pada verifikasi langsung. Ringkasan memakai presensi
  dari permintaan tersebut, termasuk bila antrean selesai pada hari berikutnya.
- API mengembalikan HTTP **202**, ID permintaan, dan status. HTTP 202 berarti
  diterima untuk diproses; aplikasi tidak boleh langsung menganggapnya berhasil.
- `GET /api/mobile/v1/attendance-submissions/latest?action=clock-in` (atau
  `clock-out`) melanjutkan permintaan setelah aplikasi dibuka kembali.
- `GET /api/mobile/v1/attendance-submissions/{id}` memeriksa hasil. Endpoint web
  `/absensi/mobile/presensi/proses/{id}` mendukung Inertia dan JSON. Semua
  endpoint memeriksa pemilik dan permission tindakan tersebut.
- Foto profil disalin secara immutable berdasarkan hash isi per pengguna.
  Perubahan foto profil setelah pengiriman tidak mengubah bahan verifikasi.
  Snapshot dengan isi sama dipakai ulang. Tidak ada penghapusan foto otomatis.
- Pengiriman ulang dengan bukti GPS yang sama mengembalikan permintaan semula.
  Permintaan aktif lain untuk pegawai dan tindakan yang sama tidak membuat job
  baru. Job menggunakan lock, token pemrosesan, dan transaksi untuk mencegah
  pencatatan ganda. Jadwal, cuti, dan status presensi diperiksa lagi saat finalisasi.
- Wajah tidak cocok, wajah tidak jelas, atau spoofing langsung menghasilkan
  kegagalan yang terlihat. Pegawai dapat mengambil foto dan mengirim bukti baru.
- Gangguan layanan dicoba maksimal tiga kali dengan jeda 10 dan 30 detik.
  Worker memiliki timeout 60 detik, lock 75 detik, dan `retry_after` 90 detik.
  Pertahankan timeout HTTP layanan wajah di bawah timeout worker (default 45 detik).
- Scheduler menjalankan `attendance:recover` setiap menit. Permintaan yang
  belum ditangani atau ditinggalkan worker selama setidaknya dua menit dikirim
  ulang. Pengiriman job yang gagal tidak membuang permintaan yang sudah tersimpan.
  Antrean berumur lebih dari lima menit dicatat sebagai warning pada log.

## Worker

Compose menyediakan dua worker `attendance`, satu worker `notifications,default`,
dan scheduler. Worker absensi menunggu layanan wajah sehat sebelum mulai.
Notifikasi push pengajuan cuti, lembur, dan perubahan shift masuk ke queue
`notifications`. Penyimpanan pengajuan, inbox, saldo, dan keputusan approval
tetap dalam transaksi langsung; push dilepas setelah commit.

Konfigurasi awal pengujian server 12 unit CPU / 15 GiB RAM:

```dotenv
QUEUE_CONNECTION=database
ATTENDANCE_QUEUE_ENABLED=true
DB_QUEUE_RETRY_AFTER=90
FACE_SERVICE_TIMEOUT=45
FACE_SERVICE_WORKERS=2
FACE_SERVICE_INTRAOP_THREADS=2
```

Jumlah proses wajah dan thread adalah titik awal pengujian. Setiap proses
memuat model sendiri; pantau memori sesudah model hangat dan saat ramai. Batas
thread TensorFlow/OMP/OpenBLAS mencegah setiap proses meminta seluruh CPU.
Untuk instalasi baru, tunggu unduhan dan warm-up model selesai sebelum uji.

Instalasi tanpa Docker memerlukan process manager yang menjaga proses berikut:

```sh
php artisan queue:work --queue=attendance --sleep=1 --tries=3 --timeout=60 --max-time=3600
# Jalankan perintah worker attendance di atas sebanyak dua proses.
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=60 --max-time=3600
php artisan schedule:work
```

`composer dev` menangani seluruh nama queue dalam satu listener untuk development.
Script `npm run services:start` pada wrapper Android juga memulai worker dan
scheduler development. Setelah memperbarui kode, restart worker yang sudah lama
berjalan. Queue dan scheduler wajib aktif agar permintaan selesai.

## Deployment Docker

Backup database, volume storage, dan `.env.docker` terlebih dahulu. Gunakan
pasangan `-f compose.yml -f compose.https.yml` pada semua perintah jika server
memakai konfigurasi HTTPS langsung dari proyek.

```sh
docker compose --env-file .env.docker build
docker compose --env-file .env.docker stop web app queue queue-attendance queue-attendance-2 scheduler
docker compose --env-file .env.docker up -d --force-recreate init
docker compose --env-file .env.docker wait init
# Lanjutkan hanya bila init berhasil (exit code 0).
docker compose --env-file .env.docker up -d --force-recreate
docker compose --env-file .env.docker ps -a
docker compose --env-file .env.docker logs --tail=100 queue-attendance queue-attendance-2 queue scheduler face-verification
```

Init menjalankan migrasi tabel baru. Kedua worker absensi dan worker notifikasi
harus memakai image terbaru serta volume storage yang sama dengan app.
Frontend Laravel dibangun dalam image; wrapper Android/PWA yang membuka
frontend server menggunakan halaman status yang baru tanpa perubahan native.

Untuk penghentian penerimaan antrean sementara, set
`ATTENDANCE_QUEUE_ENABLED=false` lalu recreate container Laravel. Permintaan
baru kembali ke verifikasi langsung; worker dan scheduler tetap dijalankan
untuk menyelesaikan permintaan yang sudah tersimpan. Endpoint status tetap aktif.

## Verifikasi

```sh
php artisan test --filter='QueuedAttendanceTest|ClockInTest|ClockOutTest|MobileApiTest|PengajuanNotificationTest|MobileNotificationTest'
node --test tests/frontend/attendance-submission.test.mjs tests/frontend/attendance-location.test.mjs tests/frontend/mobile-refresh.test.mjs
npm run build
```

Pada PHP portable Windows, jalankan PHPUnit dengan ekstensi SQLite yang tersedia:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter=QueuedAttendanceTest
```

Pengujian fitur queue mengaktifkan `attendance.queue_enabled` secara eksplisit.
Pengujian alur sinkron yang sudah ada tetap memeriksa jalur fallback.
Uji otomatis memalsukan perbandingan wajah; durasi DeepFace yang sesungguhnya
dan kapasitas server tetap harus diukur di lingkungan pengujian server.

Pemeriksaan operasional:

```sh
docker compose --env-file .env.docker exec app php artisan attendance:queue-status
docker compose --env-file .env.docker logs --tail=100 queue-attendance queue-attendance-2 scheduler
docker stats --no-stream
free -h
df -h /
```

Status `failed` pada permintaan juga mencakup foto ditolak. Kolom `Job gagal`
memisahkan kegagalan eksekusi worker yang tersimpan pada `failed_jobs`.
Simulasi otomatis memasukkan 300 permintaan ke database queue dan menguji
pengiriman job ganda; pengujian ini memeriksa konsistensi, bukan kecepatan model.

Sebelum digunakan 300 pegawai, ukur penerimaan HTTP dan waktu selesai verifikasi
untuk lonjakan 30, 100, lalu 300 akun uji, termasuk koneksi putus, job terkirim
ulang, dan restart worker. Pantau antrean `attendance`, `failed_jobs`, penggunaan
CPU/RAM sesudah warm-up, dan disk. Cek pula backup serta pemulihannya. Foto
hasil kamera web sudah diperkecil hingga sisi terpanjang 1024 piksel dan JPEG
kualitas 0,86; ukur ukuran file nyata sebelum menetapkan kebijakan penyimpanan.

### Uji HTTP dengan verifikasi wajah sesungguhnya

Gunakan akun khusus pengujian pada staging, masing-masing terhubung ke pegawai,
shift, foto profil yang sesuai, dan permission absensi. Script ini membuat
permintaan absensi nyata pada target. Untuk clock-out, akun uji harus sudah
memiliki clock-in berhasil. Gunakan kumpulan akun baru/tanggal uji baru untuk
setiap pengulangan clock-in agar tidak terkena aturan satu absensi per tanggal.

Siapkan manifest JSON privat berisi satu token API per akun dan foto selfie
yang cocok. Path foto relatif terhadap lokasi manifest. Simpan token di luar
Git, misalnya pada `storage/framework/testing/load-accounts.json`:

```json
[
  {"token": "TOKEN_AKUN_UJI", "photo": "selfies/pegawai-001.jpg", "latitude": -6.2, "longitude": 106.8166667, "accuracy": 8}
]
```

Koordinat adalah data simulasi; sesuaikan dengan lokasi kantor staging dan
pastikan jaringan mesin penguji memenuhi kebijakan jaringan staging. Foto dan
anti-spoofing tetap mengikuti pemeriksaan layanan wajah sesungguhnya.

```sh
node scripts/load-attendance.mjs https://staging.domain-anda.id/api/mobile/v1 storage/framework/testing/load-accounts.json 30 clock-in
# Untuk lonjakan penuh, gunakan 300 akun berbeda dan concurrency 300:
node scripts/load-attendance.mjs https://staging.domain-anda.id/api/mobile/v1 storage/framework/testing/load-accounts-300.json 300 clock-in
```

Script melaporkan p50/p95/maksimum waktu penerimaan HTTP dan waktu selesai
verifikasi, jumlah berhasil/gagal, serta alasan kegagalan tanpa mencetak token.
Ia menunggu hasil maksimal sepuluh menit per permintaan. Jalankan pemantauan
server bersamaan dengan uji dan bandingkan kondisi satu serta dua proses model.
