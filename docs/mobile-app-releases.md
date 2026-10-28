# Pengelolaan rilis APK

Menu **Settings → Versi Aplikasi** tersedia bagi super-admin. Akses dapat dibagikan
melalui permission `app-releases.view`, `app-releases.manage`, dan
`app-releases.publish`. Pengubah kebijakan rilis aktif juga memerlukan permission publish.

1. Naikkan `versionCode` dan `versionName` di `apps/absensi-mobile/android/app/build.gradle`.
2. Jalankan `npm run mobile:apk:release` dengan keystore produksi yang sama.
   Hasilnya `artifacts/android/arsy-presensi-build-{versionCode}.apk`; build tidak menerbitkan rilis secara otomatis.
3. Unggah APK melalui admin-web, isi catatan pembaruan, lalu simpan draf.
4. Unduh draf dari admin-web dan uji pada perangkat. Tombol unduh draf memerlukan login admin.
5. Terbitkan rilis. Halaman instalasi `/` menampilkan metadata dan unduhan rilis aktif.

Draf dapat dihapus lalu diunggah ulang dengan build yang sama untuk mengganti hasil
pengujian yang belum diterbitkan. Rilis yang pernah diterbitkan tidak dapat dihapus.

Versi dan package dibaca dari manifest APK menggunakan aapt. apksigner memverifikasi
tanda tangan file lengkap. Hanya APK release penuh untuk `id.rsarsy.presensi`, dengan
sertifikat produksi yang cocok, diterima. Fingerprint sertifikat publik produksi
dipasang pada `config/mobile.php`; dapat diganti dengan `MOBILE_ANDROID_SIGNING_SHA256`
ketika menjalankan instalasi terpisah dengan identitas signing berbeda. Keystore dan
password tidak diunggah ke admin-web. AAB, split APK, debug APK, APK unsigned,
sertifikat berbeda, serta versi duplikat ditolak.

APK disimpan dengan nama acak di `storage/app/private/mobile-releases/`. Backup
database dan seluruh folder ini. Volume `app-storage` di Compose sudah mempertahankan
file saat deploy ulang. Tidak perlu storage symlink untuk APK. Unduhan publik hanya
membuka rilis aktif, dan tidak mengekspos draf, arsip, maupun path penyimpanan.

Nilai **minimum build 1** menjadikan pembaruan opsional. Nilai lebih tinggi membuat
build di bawahnya wajib diperbarui. Isi **Mulai wajib diperbarui (WIB)** untuk memberi
masa tenggang; kosong berarti langsung saat publikasi. Waktu masukan WIB dinormalisasi
ke timezone aplikasi sebelum disimpan. Kebijakan dapat disunting pada rilis aktif.

Android build 3 (1.2.0) mendukung pembaruan dari dalam aplikasi. Pengguna mengetuk
**Unduh pembaruan**, melihat progres unduhan, lalu langsung mendapat konfirmasi
**Perbarui / Pasang** dari Android tanpa membuka browser atau mencari file APK.
Jika izin pemasangan belum aktif, aplikasi membuka pengaturan **Izinkan dari sumber ini**
dan melanjutkan pemasangan setelah pengguna kembali. Konfirmasi sistem tetap diperlukan;
pembaruan tidak dipasang diam-diam. Jika pemasangan dibatalkan, tombol **Pasang pembaruan**
dapat dipakai lagi tanpa mengunduh ulang selama proses aplikasi masih berjalan.

APK diunduh ke penyimpanan privat aplikasi. Plugin hanya mengakses endpoint rilis
pada origin server yang dikonfigurasi, menolak redirect, dan memverifikasi ukuran,
SHA-256, package, versi, minimum SDK, serta sertifikat yang sama dengan aplikasi
terpasang. Rilis aktif diperiksa lagi sebelum pemasangan. File parsial dihapus
jika unduhan gagal; pengguna dapat mencoba kembali. APK yang selesai diunduh
tidak dapat diakses aplikasi lain kecuali installer yang diberi izin baca sementara.

Frontend menerima sinyal realtime melalui kanal publik `mobile.app-releases` saat
rilis diterbitkan, kebijakan rilis aktif disunting, atau rilis ditarik. Sinyal hanya
berisi penanda perubahan, dikirim setelah transaksi database tersimpan, dan tidak
memerlukan login sehingga layar login juga menerima pembaruan. APK Android yang
sedang terbuka langsung memeriksa API rilis dan menampilkan modal tanpa perlu ditutup.
Penarikan rilis menutup modal yang sudah tampil. Jika pemeriksaan sebelumnya masih
berjalan, sinyal baru dijadwalkan untuk diperiksa sesudahnya.

Frontend juga memeriksa versi saat aplikasi dibuka, kembali aktif, koneksi pulih,
dan setiap 15 detik selama aktif sebagai cadangan saat realtime terputus. Dialog ditampilkan
di halaman instalasi, login, beranda, atau akun agar tidak memotong formulir, kamera,
GPS, atau pengiriman presensi. Permintaan yang sedang dikerjakan dapat diselesaikan
sebelum dialog wajib ditampilkan pada halaman aman. Gangguan jaringan tidak
memblokir presensi. Android yang tidak memenuhi minimum SDK tidak dipaksa memasang APK.

Build 2 masih membuka unduhan melalui browser; APK lama tanpa plugin mendapat tautan
yang dapat disalin dan dibuka melalui browser, tetapi versinya tidak ditebak dan
dialognya tidak dipaksa. Pasang build 3 sekali melalui jalur lama agar pembaruan
berikutnya bisa dilakukan dari dalam aplikasi. Mengubah frontend server saja tidak
menambahkan kemampuan native ini ke APK yang sudah terpasang. Pemeriksaan ini mengatur pengalaman pengguna,
bukan pengganti autentikasi/otorisasi server. Browser/PWA/iOS tidak menerima dialog APK.

Menarik rilis menghentikan unduhan dan pemberitahuannya; tidak menghapus aplikasi
di HP. Rilis sebelumnya tidak otomatis diaktifkan. Android melarang downgrade biasa:
perbaiki kode dan terbitkan build dengan versionCode lebih tinggi. Publikasi diserialkan
dengan row lock dan tidak mengizinkan nomor build lebih rendah dari rilis terdahulu.
Pengunggah, penerbit, penarik, waktu tindakan, sertifikat, dan SHA-256 disimpan.

Sebelum rilis terkelola pertama, URL/file APK lama masih menjadi cadangan halaman
instalasi. Setelah rilis terkelola pertama, pengaturan URL lama tidak menimpa rilis
aktif dan tidak dihidupkan kembali saat rilis ditarik.

## Server

Jalankan migration baru ketika deploy. Image Docker menyediakan `aapt`, `apksigner`,
dan Java; batas PHP/Nginx menerima APK hingga 100 MB (body request hingga 110 MB).
Pada server non-Docker, instal alat tersebut dan sesuaikan `MOBILE_AAPT_PATH`,
`MOBILE_APKSIGNER_PATH`, atau `MOBILE_APKSIGNER_JAR` + `MOBILE_JAVA_PATH`.
Di Windows, default memakai SDK/JDK khusus proyek pada `.tooling`.
Pastikan batas upload PHP, Nginx, dan reverse proxy eksternal setidaknya sama.
Setelah mengubah konfigurasi, jalankan `php artisan config:clear`/cache ulang.

API publik: `GET /mobile-app/android/release?version_code=2&sdk_int=36`. Respons tidak
dicache dan hanya menyertakan rilis aktif, kompatibilitas, status pembaruan, dan
kebijakan yang sudah diterbitkan. URL unduhan: `/mobile-app/android/releases/{id}/download`.

### Pemulihan jika data distribusi terhapus

Tabel `mobile_app_distribution` memakai satu baris permanen dengan `id = 1` untuk
menyimpan rilis aktif dan nomor build tertinggi yang pernah diterbitkan. Jika baris
ini terhapus, aplikasi membuatnya kembali saat mengubah draf atau menerbitkan rilis.
Nomor build tertinggi dipulihkan dari riwayat `mobile_app_releases.published_at`;
draf yang belum pernah terbit tidak ikut dihitung. Rilis lama tidak otomatis aktif
kembali. Terbitkan draf dengan `versionCode` lebih tinggi dari riwayat publikasi.

Untuk memulihkan server sebelum kode perbaikan dipasang, buka Tinker dari root
proyek (`php artisan tinker`), atau gunakan Docker:

```sh
docker compose --env-file .env.docker exec app php artisan tinker
```

Jalankan PHP berikut. Baris yang sudah ada tidak ditimpa:

```php
DB::table('mobile_app_distribution')->insertOrIgnore([
    'id' => 1,
    'release_id' => null,
    'highest_version_code' => (int) App\Models\MobileAppRelease::whereNotNull('published_at')->max('version_code'),
]);
DB::table('mobile_app_distribution')->where('id', 1)->first();
```

Setelah itu, coba terbitkan draf lagi. Migration awal yang sudah tercatat tidak
mengisi ulang baris yang terhapus ketika `php artisan migrate` dijalankan kembali.
Simpan tabel distribusi dan riwayat rilis dalam backup yang sama.

Rujukan alat verifikasi: https://developer.android.com/tools/apksigner

## Pengujian pembaruan Android

Tes frontend: `node --test tests/frontend/mobile-app-update.test.mjs`.
Tes kanal rilis: `node --test tests/frontend/app-update-realtime.test.mjs`.
Tes integritas unduhan native: `:app:testDebugUnitTest` atau `:app:testReleaseUnitTest`.

Uji pada perangkat dengan build 3 terpasang dan rilis bertanda tangan sama dengan
`versionCode` lebih tinggi: buka aplikasi, ketuk Unduh pembaruan, periksa progres,
aktifkan izin pemasangan jika diminta, lalu konfirmasi pemasangan Android. Buka
kembali aplikasi dan pastikan modal hilang pada versi terbaru serta sesi login tetap ada.
Ulangi dengan izin ditolak, pemasangan dibatalkan, jaringan terputus, rilis ditarik,
dan aplikasi dipindahkan ke latar belakang selama unduhan. Browser/PWA/iOS serta
formulir dan proses presensi yang sedang berjalan tidak boleh mendapat modal APK.

Untuk menguji realtime, biarkan aplikasi terbuka di beranda atau login, lalu terbitkan
rilis baru dari admin-web. Modal harus tampil tanpa navigasi atau menutup aplikasi.
Tarik rilis tersebut dan pastikan modal tertutup. Ulangi dengan WebSocket terputus;
pemeriksaan cadangan mendeteksi perubahan dalam sekitar 15 detik saat jaringan tersedia.
Pastikan Reverb aktif dan `MOBILE_REALTIME_ENABLED=true` pada server untuk sinyal langsung.
Build 4 (1.2.1) tersedia sebagai paket berikutnya; build 1/2 tetap harus memasang APK
yang memiliki plugin pembaruan sekali melalui browser sebelum alur pemasangan langsung tersedia.
