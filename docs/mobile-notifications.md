# Notifikasi pengajuan pada absensi-mobile

Pemohon menerima notifikasi database dan push ketika pengajuan cuti selesai
disetujui seluruh approver. Persetujuan antara hanya memberi notifikasi kepada
approver berikutnya; pemohon belum mendapat status disetujui. Penolakan juga
memberi notifikasi kepada pemohon. Akun cukup memiliki `mobile.access` dan email
terverifikasi, tanpa perlu akses admin-web.

Lonceng di beranda dan halaman cuti membuka **Inbox** dengan jumlah notifikasi
belum dibaca. Tab **Notifikasi** menampilkan riwayat berdasarkan tanggal, status
pengajuan, paginasi, tombol **Baca semua**, dan pengaturan notifikasi perangkat.
Tab **Butuh persetujuan** menampilkan kategori pengajuan; hanya **Cuti** yang aktif,
sedangkan kategori lain berlabel **Segera hadir** dan tidak dapat dipilih.
Header Inbox rata tengah dan tetap terlihat saat halaman digulir.
Kategori Cuti membuka halaman khusus **Persetujuan Cuti** di `/absensi/mobile/notifikasi/cuti`.
Halaman ini memiliki pencarian nama/unit/jenis cuti, filter periode, pengurutan,
rincian pengajuan, serta tindakan setujui/tolak tanpa membuka halaman Delegasi.
Kotak centang langsung tampil pada setiap pengajuan. Centang satu per satu atau
gunakan **Pilih semua di halaman ini**, lalu ketuk **Setujui pilihan**. Konfirmasi
menampilkan nama, jenis, tanggal, dan jumlah pengajuan sebelum disetujui sekaligus.
Pilihan dihapus saat pencarian, filter, atau halaman berubah agar pengajuan di luar
daftar tidak ikut disetujui. Persetujuan diperiksa ulang oleh server dan disimpan
dalam satu transaksi; jika salah satu pengajuan sudah diproses atau bukan giliran
pengguna, seluruh pilihan tidak disimpan. Selama penyimpanan tombol dinonaktifkan.
Antrean mencakup seluruh periode dan hanya pengajuan yang menjadi giliran pengguna;
angka pada Inbox menggunakan cakupan yang sama. Pilihan pencarian dan filter
dipertahankan dalam URL saat memuat ulang atau berpindah halaman.

Ketukan pada push membuka detail pengajuan yang bersangkutan,
termasuk pengajuan pada bulan sebelumnya. Apabila sesi PWA sudah habis, login
melanjutkan pembukaan notifikasi. Endpoint selalu memeriksa pemilik notifikasi
dan hak akses atas pengajuan.

## Kanal pengiriman

- PWA/browser: Web Push dengan VAPID, memakai service worker
  `/mobile-notifications-sw.js` dan manifest `/absensi-mobile.webmanifest`.
- Android Capacitor: `@capacitor/push-notifications` menerima token FCM; server
  mengirim melalui FCM HTTP v1 dengan service account dan OAuth 2.0.
- iOS Capacitor: plugin yang sama menerima token **APNs**, bukan token FCM.
  Server mengirim langsung melalui APNs HTTP/2 dengan kunci `.p8` dan JWT ES256.

Push mobile hanya memuat status umum, ID notifikasi acak, dan URL internal untuk
membuka notifikasi. Nama pegawai, jenis/tanggal cuti, dan alasan tidak dikirim
ke provider push. Detail tersedia di aplikasi setelah autentikasi. Tidak ada
pengiriman percobaan ke perangkat nyata dalam tes otomatis.

## Logo pada notifikasi

Web/admin dan PWA mobile memakai logo aplikasi berwarna sebagai `icon` serta
siluet logo putih transparan sebagai `badge` untuk tampilan kecil yang didukung
browser/OS. Android native memakai resource `ic_stat_notification` dari simbol
logo yang sama, dengan aksen hijau. Resource ini dipakai oleh FCM saat aplikasi
di latar belakang maupun oleh plugin saat aplikasi terbuka. Android mewarnai
ikon kecil sesuai tampilan sistem, sehingga bentuk logo tetap dipakai dalam
versi monokrom. Browser dapat tetap menampilkan identitas browser di sampingnya.

iOS native menggunakan AppIcon yang dibundel dalam aplikasi. APNs tidak memiliki
parameter untuk mengganti ikon aplikasi per notifikasi. PWA iPhone memakai ikon
aplikasi Layar Utama; manifest dan Apple touch icon sudah menunjuk ke logo
ARSY Presensi. Tampilan akhir mengikuti dukungan browser/OS.

Perubahan Android memerlukan build dan pemasangan APK terbaru. Perubahan iOS
memerlukan build/signing dan pemasangan aplikasi dari Xcode/TestFlight. Untuk
web/PWA, deploy aset publik termasuk kedua service worker, buka ulang aplikasi
agar worker diperbarui, lalu uji dengan pengajuan dan persetujuan seperti biasa.
Jika ikon instalasi PWA masih lama, perbarui/pasang ulang PWA. Aktifkan notifikasi
perangkat melalui Inbox dan pastikan worker antrean server berjalan.

Pembuatan ulang aset dijelaskan pada [branding.md](branding.md).

## Persiapan server

```sh
composer install
php artisan migrate
php artisan notifications:vapid
php artisan config:clear
npm run build
php artisan queue:work --queue=notifications,default --tries=3
```

Jika VAPID sudah diisi, jangan mengganti pasangan kuncinya. Panduan VAPID/OpenSSL
Windows ada pada [admin-notifications.md](admin-notifications.md).

Gunakan `QUEUE_CONNECTION=database` dan worker yang terus berjalan pada server.
Notifikasi disimpan dalam transaksi approval, sedangkan job push dilepas setelah
commit, dengan tiga percobaan dan jeda 30/120 detik. Kegagalan push tidak menghapus
riwayat. Web Push dan native memakai job terpisah, sehingga kegagalan satu kanal
tidak mengulang kanal lain. Percobaan ulang pada kanal yang sama dapat mengirim
kembali ke perangkat yang sebelumnya berhasil; ID/tag notifikasi tetap sama.

## PWA pada Android dan iPhone

Gunakan HTTPS (localhost hanya untuk development). Buka lonceng dan tekan
**Aktifkan notifikasi**, kemudian izinkan permintaan sistem. Izin tidak diminta
otomatis saat halaman dibuka. Izin yang sudah diberikan didaftarkan kembali
setelah login tanpa meminta persetujuan browser lagi.

Pada iPhone/iPad, Web Push memerlukan iOS/iPadOS **16.4 atau lebih baru** dan
aplikasi yang ditambahkan ke **Layar Utama**. Buka dari ikon Layar Utama, login,
kemudian aktifkan notifikasi melalui tombol tersebut. Tab Safari biasa tidak
menyediakan jalur Web Push ini. Notifikasi dapat diterima ketika halaman ditutup,
selama izin dan pengaturan browser/OS mengizinkannya. Service worker tidak
menyimpan halaman atau data pegawai untuk penggunaan offline.

## Android native

1. Daftarkan aplikasi Firebase dengan package `id.rsarsy.presensi` dan aktifkan
   Cloud Messaging API.
2. Simpan konfigurasi klien Firebase di
   `apps/absensi-mobile/android/app/google-services.json` (diabaikan Git).
3. Simpan JSON service account **hanya di server**, di luar direktori publik.
   Isi path absolut pada `.env`:

   ```dotenv
   FCM_CREDENTIALS=/path/private/firebase-service-account.json
   ```

4. Jalankan `php artisan config:clear`, restart worker, lalu bangun/sinkronkan
   ulang wrapper: `npm run mobile:sync` dan `npm run mobile:android` atau
   `npm --prefix apps/absensi-mobile run android:run`.
5. Login dan aktifkan notifikasi. Android 13+ menampilkan permintaan izin sistem.

Jika izin Android sudah diberikan sebelumnya, tombol aktivasi tidak menampilkan
popup izin lagi. Aplikasi melanjutkan pendaftaran token dan menampilkan
**Notifikasi aktif** setelah server menyimpannya. Status izin dapat diperiksa
melalui Pengaturan Android > Aplikasi > RSARSY Presence > Notifikasi.

Jangan menaruh service account di `google-services.json`, variabel `VITE_*`,
frontend, atau Git. Konfigurasi klien dan service account harus mengacu pada
proyek Firebase yang sama. Channel Android `pengajuan` memakai suara/getaran dan
ikon status khusus. Wrapper development tanpa konfigurasi Firebase tetap bisa
dibangun, tetapi belum dapat mendaftarkan push native.

## iOS native

Proyek sudah menyertakan callback registrasi APNs di `AppDelegate.swift`, capability
Push Notifications, entitlement, dan dependensi plugin melalui Swift Package
Manager. Sinkronisasi proyek sudah diperbarui untuk plugin ini.

Pada macOS/Xcode, pilih Apple Developer Team dan provisioning profile yang
mengizinkan Push Notifications untuk bundle ID `id.rsarsy.presensi`. Buat kunci
APNs dari Apple Developer, simpan file `.p8` hanya di server, lalu isi:

```dotenv
APNS_KEY_ID=ID_KUNCI_APPLE
APNS_TEAM_ID=ID_TEAM_APPLE
APNS_PRIVATE_KEY_PATH=/path/private/AuthKey.p8
APNS_TOPIC=id.rsarsy.presensi
APNS_SANDBOX=true
```

`APNS_SANDBOX=true` untuk build development. Untuk TestFlight/App Store gunakan
`false`. Debug memakai entitlement development; Release memakai production.
Gunakan konfigurasi server terpisah untuk masing-masing lingkungan karena token
APNs terikat pada lingkungan. Server perlu cURL dengan dukungan HTTP/2 dan waktu
sistem yang benar. Restart worker setelah konfigurasi berubah.

Jalankan `npm --prefix apps/absensi-mobile run cap:sync`, kemudian buka proyek di
Xcode dan uji pada perangkat Apple. Build/signing dan pengiriman nyata iOS tidak
dapat diverifikasi dari Windows. Batasan wrapper development/produksi yang ada
di [README mobile](../apps/absensi-mobile/README.md) tetap berlaku.

## Siklus perangkat dan pengujian

Token yang diperbarui mengganti token sebelumnya untuk sesi tersebut. Perangkat
yang berpindah akun dipetakan ke pemilik terbaru. Logout menghapus perangkat
native serta subscription mobile/admin yang didaftarkan sesi tersebut, dengan
pemeriksaan pemilik; perangkat pada sesi lain tetap tersedia. Token FCM
`UNREGISTERED` dan APNs `410 Unregistered` dihapus. Error konfigurasi/provider
tetap dipertahankan untuk percobaan ulang. Pencabutan akses mobile menghentikan
pengiriman berikutnya.

```sh
php vendor/phpunit/phpunit/phpunit --filter="MobileNotificationTest|PengajuanNotificationTest|CutiPageTest|ApprovalLineCutiTest|AuthenticationTest"
node --test tests/frontend/mobile-notifications-runtime.test.mjs tests/frontend/mobile-notifications.test.mjs tests/frontend/admin-notifications-sw.test.mjs
npm run build
```

Tes mencakup approval akhir/bertahap, rollback, pemohon tanpa akses admin, riwayat,
isolasi akun, login ulang dari push, rotasi token, logout lintas portal, payload
generik, JWT RS256/ES256 yang diverifikasi, penghapusan token kedaluwarsa, retry,
serta service worker dan pembatasan URL ketukan. HTTP provider dipalsukan dalam
tes; kredensial produksi tidak dibaca.

Referensi resmi: [Capacitor Push Notifications](https://capacitorjs.com/docs/apis/push-notifications),
[FCM HTTP v1](https://firebase.google.com/docs/cloud-messaging/send/v1-api),
[APNs token authentication](https://developer.apple.com/documentation/usernotifications/establishing-a-token-based-connection-to-apns),
[Web Push iOS/iPadOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/).


Persetujuan presensi di luar radius dan integrasi realtime dijelaskan pada
[presensi-approval.md](presensi-approval.md).
