# Notifikasi pengajuan pada admin-web

Setiap pengajuan cuti atau lembur yang berhasil disimpan, baik dari mobile maupun admin-web,
menghasilkan notifikasi database dan job Web Push. Semua jenis cuti memakai alur
yang sama, termasuk cuti tahunan dan cuti khusus. Pengajuan lembur dari mobile juga
masuk ke notifikasi web, daftar **Pengajuan Butuh Approval**, dan badge persetujuan
saat mencapai giliran approver. Detail lembur di web menyediakan waktu shift,
durasi dan istirahat, kompensasi, alasan, unduhan lampiran, riwayat persetujuan,
serta tindakan setujui/tolak. Keputusan web dan mobile memakai transaksi yang sama.
Approver web memakai izin `cuti.view`, `cuti.approve`, dan `notifications.view`
yang sudah digunakan menu persetujuan; akses mobile tidak diperlukan. Penugasan
lembur di approval line maupun riwayat pengajuan memberikan akses portal persetujuan,
tanpa memberikan izin modul admin lain.

## Penerima dan kejadian

- Pengajuan baru: super-admin dan approver tingkat pertama.
- Approval berlanjut: super-admin dan approver yang baru mendapat giliran.
- Disetujui, ditolak, atau dibatalkan: super-admin dan approver terkait. Pemohon
  juga menerima notifikasi melalui portal mobile bila memiliki `mobile.access`,
  atau melalui admin-web bila memiliki aksesnya.
- Pelaku perubahan tidak menerima notifikasi dari tindakannya sendiri.
- Notifikasi tidak memberikan izin baru. Detail tetap memeriksa pemohon,
  penugasan approver, atau role super-admin. Admin biasa tidak mendapatkan
  informasi pengajuan pengguna lain hanya karena memiliki `admin.access`.

Lonceng pada navbar menampilkan jumlah belum dibaca dan delapan notifikasi terbaru.
Menu **Notifikasi > Semua Notifikasi** menyimpan seluruh riwayat dengan paginasi,
termasuk penolakan dan pembatalan. Membuka notifikasi menandainya dibaca dan
menampilkan detail pengajuan yang bersangkutan. Tersedia tombol tandai semua
dibaca. Data diperbarui setiap 20 detik saat halaman terlihat, saat kembali ke tab,
dan saat service worker menerima push.

Badge sidebar **Semua Notifikasi** menampilkan jumlah notifikasi belum dibaca.
Badge **Pengajuan Butuh Approval** menampilkan jumlah pengajuan yang sedang
menunggu giliran pengguna tersebut, tanpa dipengaruhi pencarian atau paginasi.
Menu induk **Notifikasi** juga menampilkan badge gabungan kedua jumlah tersebut,
termasuk saat submenu tertutup atau sidebar diperkecil. Tooltip menu menjelaskan
rincian jumlah notifikasi dan pengajuan approval.
Keduanya diperbarui bersama feed lonceng serta setelah navigasi dan keputusan
approval.

Pada **Pengajuan Butuh Approval**, centang beberapa pengajuan atau gunakan
kotak centang pada judul tabel untuk memilih seluruh pengajuan di halaman itu.
Klik **Setujui terpilih**, periksa ringkasannya, lalu konfirmasi persetujuan.
Pilihan dibersihkan saat berpindah halaman, mengganti pencarian, atau kategori.
Persetujuan hanya berlaku pada tingkat approval pengguna; tingkat berikutnya
berjalan seperti biasa. Server memeriksa ulang status dan approver dalam satu
transaksi. Jika satu pengajuan tidak tersedia, sudah berubah, atau tidak boleh
disetujui pengguna, seluruh batch dibatalkan, termasuk notifikasi dan job push.
Endpoint menerima maksimal 100 pengajuan unik per permintaan. ID cuti dikirim
melalui `request_ids`, dan ID lembur melalui `lembur_request_ids`, sehingga ID
yang sama pada kedua jenis pengajuan tidak tertukar. Seluruh batch, termasuk
campuran cuti dan lembur, diproses dalam satu transaksi.

Tab **Pengajuan Butuh Approval** menyediakan bagian **Semua pengajuan**,
**Pengajuan Cuti**, **Pengajuan Lembur**, dan **Perubahan Shift**. Jumlah pada setiap
bagian mengikuti giliran approver pengguna dan tidak dipengaruhi pencarian atau
paginasi. Filter jenis diproses sebelum paginasi dan tetap digunakan saat mencari
atau berpindah halaman. Berpindah bagian membersihkan pilihan persetujuan massal
dan menutup detail yang terbuka. Bagian **Perubahan Shift** menampilkan kondisi
kosong dengan jumlah nol karena alur pengajuan perubahan shift belum tersedia.

## Menjalankan

Notifikasi pemohon untuk PWA, Android, dan iOS dijelaskan pada
[mobile-notifications.md](mobile-notifications.md).

```sh
composer install
php artisan migrate
php artisan notifications:vapid
php artisan config:clear
npm run build
php artisan queue:work --queue=notifications,default --tries=3
```

Perintah `notifications:vapid` menyimpan pasangan kunci di `.env` dan menolak
mengganti kunci yang sudah ada. Simpan kunci yang sama saat deployment berikutnya
agar subscription browser tetap berlaku. Isi `WEBPUSH_VAPID_SUBJECT` dengan alamat
kontak yang valid, misalnya `mailto:admin@rumahsakit.example`, dan simpan kunci
privat hanya pada server. Nilai publik dibagikan melalui endpoint feed hanya
kepada pengguna terautentikasi yang berhak mengakses notifikasi.

Gunakan `QUEUE_CONNECTION=database` dan jalankan worker sebagai layanan pada
server produksi. `composer dev` juga sudah menjalankan queue listener. Job push
dilepas setelah transaksi pengajuan commit, dicoba maksimal tiga kali dengan
jeda 30 dan 120 detik, dan subscription kedaluwarsa (HTTP 404/410) dihapus.
Kegagalan pengiriman push tidak menghapus notifikasi di dalam aplikasi.

Pada admin-web, klik lonceng lalu **Aktifkan push** dan izinkan notifikasi browser.
Web Push membutuhkan HTTPS atau localhost serta browser yang mendukung Push API.
Service worker tidak menyimpan cache halaman dan dapat menampilkan notifikasi
saat tab admin-web ditutup, selama browser/OS masih mengizinkan penerimaan push.
Menolak izin browser tidak mengganggu riwayat notifikasi di dalam aplikasi.
Logout menghapus subscription perangkat yang didaftarkan oleh sesi tersebut;
perangkat lain tetap terdaftar.

Pada PHP Windows portable, atur `OPENSSL_CONF` pada lingkungan proses sebelum
menjalankan PHP jika lokasi default OpenSSL tidak tersedia. Contoh PowerShell
untuk instalasi Laragon yang dipakai proyek ini:

```powershell
$env:OPENSSL_CONF = 'D:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\extras\ssl\openssl.cnf'
php artisan notifications:vapid
php artisan queue:work --queue=notifications,default --tries=3
```

Sesuaikan path dengan instalasi PHP server. Variabel ini perlu diwariskan ke
proses worker; menambahkannya hanya ke `.env` Laravel setelah PHP berjalan tidak
mengubah konfigurasi OpenSSL yang sudah dimuat.

## Pengujian

```sh
php -d extension=php_pdo_sqlite.dll -d extension=php_sqlite3.dll vendor/phpunit/phpunit/phpunit --filter="NotificationApprovalTest|PengajuanNotificationTest|ApprovalLineCutiTest|ApprovalLineLemburTest|CutiPageTest|LemburRequestTest|LemburPageTest|PortalPermissionTest|MobileNotificationTest"
node --test tests/frontend/admin-notifications-sw.test.mjs tests/frontend/admin-notification-approval.test.mjs
npm run build
```

Pengujian mencakup pengajuan mobile dan web, jenis cuti berbeda, urutan approval,
penolakan, pembatalan, rollback transaksi, job setelah commit, isolasi pengguna,
status dibaca, pembatasan endpoint subscription, pergantian akun, logout,
penghapusan subscription kedaluwarsa, retry, serta enkripsi dan tanda tangan VAPID
pada request HTTP tanpa mengirim push ke perangkat nyata.

Validasi badge dan persetujuan massal: 64 tes PHP terkait dan 10 tes frontend
(termasuk lima tes service worker), build frontend, lint pada file yang diubah,
dan Laravel Pint lulus. Pemeriksaan
seluruh proyek masih menghasilkan satu error pada import `AccessPermission` di
`DashboardTest` dan enam kegagalan pada foto profil/presensi (urutan header cache,
format tanggal SQLite, filter riwayat, dan validasi duplikasi). Pemeriksaan tipe
seluruh proyek juga belum bersih karena konfigurasi `vue/tsx`/folder tipe dan
error komponen lain yang tidak diubah dalam pekerjaan ini.

## Jenis pengajuan berikutnya

`PengajuanNotificationService::send($recipients, $payload)` menyediakan jalur
notifikasi bersama. Panggil dari transaksi pengajuan baru dengan payload
`request_type`, `request_id`, `event`, `title`, `message`, dan URL detail internal
admin-web. Pilih penerima berdasarkan izin atas pengajuan tersebut dan sediakan
route detail dengan pemeriksaan akses. Jangan memanggil pengiriman Web Push
langsung dari controller pengajuan.

Referensi: [Web Push PHP](https://github.com/web-push-libs/web-push-php/tree/v10.1.0)
dan [PushManager.subscribe](https://developer.mozilla.org/en-US/docs/Web/API/PushManager/subscribe).
