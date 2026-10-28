# Sinkronisasi data mobile

PWA, Android, dan iOS menggunakan satu koneksi WebSocket Reverb untuk data mobile pada frontend
Laravel yang sama. Perubahan data melalui aplikasi memicu pengambilan ulang data
halaman yang terbuka tanpa menutup aplikasi atau memindahkan menu. Ini mencakup
master dan penugasan shift, kalender libur, profil pegawai, akun dan hak akses,
lokasi kantor, presensi, kuota/saldo, seluruh pengajuan beserta approval,
serta notifikasi termasuk status baca. Push perangkat tetap mengikuti aturan
notifikasi yang sudah ada; sinkronisasi tidak meminta izin notifikasi.

Android juga memakai koneksi publik untuk pemberitahuan rilis APK, termasuk sebelum
login. Alur ini dijelaskan di [pengelolaan rilis APK](mobile-app-releases.md).

## Cara kerja

`MobileDataChanges` mendeteksi penulisan SQL dari Laravel, termasuk Query Builder,
update massal, impor, dan perubahan pivot relasi. Seluruh penulisan domain dalam
satu request atau job digabung menjadi satu sinyal setelah commit. Rollback tidak
mengirim sinyal. Session, cache, antrean, dan registrasi perangkat dikecualikan
agar pengambilan data tidak memicu siklus pembaruan tanpa henti. Modul/tabel baru
ikut terdeteksi tanpa perlu menambahkan observer khusus.

Sinyal `mobile.data.changed` hanya membawa versi acak, tanpa ID pengguna, isi
pengajuan, nama pegawai, atau SQL. Kanal `private-mobile.data` hanya dapat diikuti
akun terverifikasi dengan akses mobile. Sinyal ini dibagikan ke sesi mobile yang
berwenang; setiap sesi mengambil data miliknya melalui endpoint dengan pemeriksaan
akses yang biasa. Karena satu sinyal dapat memicu beberapa sesi, lakukan uji
beban sesuai jumlah pengguna aktif sebelum menambah kapasitas layanan.

Semua properti data halaman mobile diperbarui; filter URL, pesan formulir, draf
lokal, dan posisi gulir dipertahankan. Identitas serta permission akun juga
diambil ulang. Layar Clock In/Out menunda pembaruan selama pengambilan GPS atau
pengiriman verifikasi; pembaruan dilanjutkan setelah operasi selesai. Token bukti
lokasi tidak dirotasi oleh pembaruan data biasa. Pengambilan foto tetap berjalan
ketika properti jadwal/lokasi diperbarui.

Saat tersambung dan berhasil berlangganan kanal, polling pemeriksaan berjalan
setiap 60 detik. Jika WebSocket tidak tersambung/otorisasi gagal, polling menjadi
10 detik. Koneksi dan polling dihentikan saat halaman tersembunyi/offline.
Berlangganan ulang, kembali ke aplikasi, kembali melalui history, atau koneksi
pulih langsung memicu pengambilan data. Kegagalan Reverb tidak menggagalkan
penyimpanan presensi/pengajuan; aplikasi memakai polling.

Perubahan penugasan shift tetap mengikuti aturan presensi: jadwal pada catatan
yang sudah clock-in memakai shift catatan tersebut. Sinkronisasi tidak mengubah
aturan bisnis ataupun memindahkan shift catatan presensi secara otomatis.

Penulisan langsung ke database dari aplikasi lain/SQL client tidak melewati
pendeteksi Laravel, sehingga terlihat pada polling pemeriksaan berikutnya.
Perubahan file konfigurasi/aset membutuhkan prosedur deploy dan restart layanan.

## Development Windows

```powershell
composer install
npm install
php scripts/realtime-setup.php
php artisan config:clear
composer run dev
```

`composer run dev` menjalankan Reverb pada `127.0.0.1:8080` bersama server,
antrean dan Vite. Hindari menjalankan dua proses Reverb pada port yang sama.
Untuk frontend mobile pada port 8600, `npm run services:start` dari folder
`apps/absensi-mobile` menyiapkan konfigurasi serta menjalankan Reverb tersembunyi
dengan log/PID yang dapat dihentikan melalui `services:stop`. `android:connect`
menghubungkan port 8080 melalui `adb reverse` selain port Laravel/Vite.
Uji native iOS terhadap host HTTPS yang dapat dijangkau perangkat.

Script setup hanya menambah konfigurasi realtime, mempertahankan credential dan
endpoint khusus yang sudah ada, dan tidak menampilkan rahasia.

## Docker / production

Instalasi baru melalui `scripts/docker-setup.php` otomatis membuat credential
Reverb. Untuk `.env.docker` yang sudah ada:

```powershell
php scripts/realtime-setup.php --env-file=.env.docker --docker
```

Kemudian jalankan prosedur rebuild/deploy proyek. `compose.yml` menyediakan
layanan `reverb` dengan restart otomatis serta healthcheck. Template Nginx HTTP
dan HTTPS meneruskan `/app/` ke Reverb, sehingga browser/native memakai origin
dan sertifikat aplikasi yang sama; port Reverb tidak dipublikasikan ke host.
Koneksi broadcast server memakai jaringan internal Docker. Restart/recreate
Reverb bersama perubahan backend/configuration saat deploy.

Frontend mendapat public key/endpoint dari props server saat runtime; tidak perlu
menaruh credential dalam variabel `VITE_*` atau membangun APK baru. Secret Reverb
tetap di server. `REVERB_ALLOWED_ORIGINS` dapat diisi daftar hostname dipisahkan
koma jika ada beberapa domain; default memakai hostname `APP_URL`.

Untuk deployment non-Docker, jalankan `php artisan reverb:start` melalui pengelola
proses dan teruskan WebSocket melalui reverse proxy HTTPS. `REVERB_HOST/PORT/SCHEME`
adalah endpoint internal pengiriman server; `MOBILE_REALTIME_HOST/PORT/SCHEME/PATH`
opsional mengatur endpoint publik. Jika endpoint publik kosong, origin halaman
dipakai. Untuk produksi dengan reverse proxy, kosongkan `MOBILE_REALTIME_PORT`
yang dibuat untuk development lokal.

## Verifikasi

```powershell
node --test tests/frontend/mobile-realtime.test.mjs tests/frontend/mobile-refresh.test.mjs
node --experimental-websocket --test tests/integration/mobile-realtime.test.mjs
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/MobileRealtimeTest.php
```

Tes integrasi menjalankan server Reverb sementara dengan credential uji dan
SQLite memory, membuktikan penulisan bulk yang sudah commit diterima melalui
kanal privat. Tidak memakai database pegawai atau provider push nyata.

Uji dua sesi: buka mobile lalu ubah shift/profil/lokasi dari admin, proses
pengajuan tiap jenis, dan tandai notifikasi dibaca dari sesi lain. Periksa data
berubah sambil mempertahankan draf, putus/sambungkan jaringan, pindahkan aplikasi
ke latar belakang, lalu ulangi setelah logout/pergantian akun. Uji pengambilan
GPS/kamera dan verifikasi Clock In/Out ketika ada perubahan dari admin.
