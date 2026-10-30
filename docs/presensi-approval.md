# Persetujuan presensi di luar radius

Admin mengatur **Approval Line > Presensi (di luar radius)** per jabatan dengan
1 sampai 3 approver berurutan. Jabatan yang sama dapat memiliki alur cuti,
lembur, perubahan shift, dan presensi yang berbeda. Approver harus memiliki
email terverifikasi dan akses persetujuan/notifikasi web atau mobile. Web
mengikuti izin portal persetujuan yang sudah dipakai pengajuan lain:
`cuti.view`, `cuti.approve`, dan `notifications.view`. Mobile memakai
`mobile.access` dan `mobile.notifications` serta penugasan pada pengajuan.

Clock-in dan clock-out di luar radius masing-masing membuat pengajuan setelah
verifikasi wajah berhasil. GPS, foto, waktu penerimaan, alasan, identitas
pemohon dan urutan approver disimpan. Pemohon wajib mengonfirmasi lokasi dan
mengisi alasan. Tanpa alur yang valid, pengajuan di luar radius ditolak dengan
pesan konfigurasi; presensi di dalam radius tetap berjalan tanpa approval.
Alur disalin saat diterima, termasuk untuk antrean verifikasi wajah, sehingga
perubahan/penghapusan konfigurasi tidak mengubah pengajuan yang sudah masuk.
Job duplikat tidak membuat pengajuan atau notifikasi ganda.

Waktu dan bukti presensi tetap tersimpan selama menunggu maupun setelah ditolak.
Status persetujuan tampil pada hasil clock-in/clock-out, rincian presensi,
log mobile dan daftar presensi admin. Record dengan persetujuan menunggu atau
ditolak tidak masuk hitungan Hadir dan filter Hadir/Tepat waktu/Terlambat.
Filter **Menunggu persetujuan** dan **Presensi ditolak** tersedia pada admin.
Log mobile juga menampilkan status tersebut dan tidak menghitungnya sebagai
tepat waktu, terlambat, atau pulang lebih awal sebelum disahkan.
Presensi lama tidak diubah dan tidak dibuatkan approval secara retroaktif.

Approver membuka **Notifikasi > Pengajuan Butuh Approval > Presensi di luar
radius** pada web atau **Inbox > Butuh persetujuan > Presensi** pada mobile.
Detail menampilkan lokasi/koordinat, radius, akurasi GPS, foto, alasan dan
riwayat persetujuan. Foto hanya dapat dibuka pemohon, approver terkait atau
super-admin melalui endpoint terautentikasi. Pemohon juga dapat membuka tab
**Presensi saya** untuk melihat semua pengajuannya.

Hanya approver yang sedang mendapat giliran dapat mengambil keputusan.
Pemohon, approver tingkat berikutnya, dan super-admin yang tidak ditugaskan
tidak dapat menyetujui. Penolakan wajib disertai alasan dan menghentikan sisa
alur. Persetujuan akhir memperbarui status pada tindakan presensi terkait;
clock-in dan clock-out tidak saling mengubah keputusan. Tanggal/jam yang
sudah diubah administrator harus diperiksa kembali sebelum dapat diputuskan.
Persetujuan massal web dapat menggabungkan presensi dan jenis pengajuan lain;
seluruh pilihan diperiksa ulang dan di-rollback jika satu saja tidak valid.

## Notifikasi dan realtime

- Pengajuan baru: approver tingkat pertama dan super-admin.
- Persetujuan antara: approver berikutnya dan super-admin.
- Disetujui seluruhnya/ditolak: pemohon, approver terkait dan super-admin.
- Pelaku tidak menerima notifikasi dari tindakannya sendiri.

Database notification ditulis di transaksi yang sama. Web Push, FCM Android,
APNs iOS dan sinyal realtime berjalan setelah commit. Web memakai kanal privat
`notifications.{userId}` dengan event `notifications.changed`; hanya pemilik
akun dengan izin notifikasi yang dapat berlangganan. Payload hanya berisi
versi perubahan, kemudian aplikasi mengambil ulang feed melalui endpoint yang
memeriksa izin. Lonceng, badge sidebar dan daftar antrean web ikut diperbarui.
Mobile mengikuti kanal invalidasi `mobile.data` yang sudah tersedia untuk
memperbarui halaman/Inbox dan badge. Polling tetap menjadi cadangan ketika
socket terputus. Detail/form yang sedang ditinjau tidak dimuat ulang oleh
socket web sehingga catatan approver tetap terjaga.

Saat aplikasi berada di latar belakang atau ditutup, jalur push yang sudah
ada menangani web/PWA, Android dan iOS. Ketukan notifikasi membuka detail
pengajuan melalui pemeriksaan pemilik notifikasi dan hak akses pengajuan.
Mobile push tetap berisi informasi umum; nama/GPS/alasan/foto tidak dikirim
ke provider push. Tidak ada perubahan plugin native yang memerlukan APK/IPA
baru untuk wrapper yang sudah mendukung push.

## Penerapan

1. Deploy backend/frontend dan jalankan `php artisan migrate`.
2. Jalankan `npm run build` untuk aset production.
3. Pastikan Reverb dan worker `attendance,notifications,default` berjalan,
   serta `BROADCAST_CONNECTION=reverb` dan `MOBILE_REALTIME_ENABLED=true`.
4. Gunakan konfigurasi VAPID, FCM dan APNs yang dijelaskan pada
   [notifikasi mobile](mobile-notifications.md) dan
   [notifikasi admin](admin-notifications.md). Perangkat harus mengizinkan push.
5. Atur approval line presensi untuk jabatan pengguna sebelum mengizinkan
   presensi di luar radius. Uji satu pengajuan nyata dari masing-masing platform.

Tes otomatis memeriksa alur langsung/antrean, urutan web/mobile, penolakan,
pembatasan akses foto/channel, rollback, idempotensi, notifikasi, dan penerapan
status pada hitungan presensi. Pengiriman ke perangkat fisik membutuhkan server
serta kredensial provider yang aktif dan tidak dilakukan oleh tes otomatis.
