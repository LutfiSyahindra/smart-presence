# Cuti pada mobile dan PWA

Menu **Cuti** tersedia di kartu **Akses cepat** pada beranda. Android dan PWA memakai halaman Laravel/Inertia yang sama di `resources/js/pages/absensi/absensi-mobile`, sehingga pembaruan berlaku pada keduanya. Prototipe API di `apps/absensi-mobile/src` tidak digunakan wrapper Android saat ini.

Halaman `/absensi/mobile/cuti` menyediakan:

- **Pengajuan saya**: pengajuan milik pengguna yang masuk, dengan filter bulan, tahun, dan status serta paginasi. Pengajuan lintas bulan tampil pada setiap bulan yang dilewatinya.
- **Delegasi**: pengajuan yang menugaskan pengguna sebagai pemberi persetujuan. Ini menggunakan penugasan approval line yang sudah ada. Hanya approver pada tingkat aktif yang dapat menyetujui atau menolak; penolakan wajib disertai catatan.
- **Persetujuan sekaligus**: tautan **Tandai & setujui sekaligus** pada Delegasi membuka antrean Persetujuan Cuti. Centang beberapa pengajuan atau pilih semua di halaman yang tampil, lalu ketuk **Setujui pilihan** dan konfirmasi. Berlaku pada mobile, PWA, dan iOS melalui halaman yang sama. Setiap keputusan mengikuti tingkat approval yang sedang aktif.
- **Saldo saya**: kuota tahunan, hari disetujui, hari menunggu, dan saldo tersedia untuk tahun periode terpilih. Saldo tidak dibuatkan angka contoh ketika alokasi belum tersedia.
- **Rincian saldo**: hak cuti aktif yang dialokasikan kepada pegawai. Jenis biasa menampilkan batas durasi per pengajuan.
- **Ajukan cuti**: pilihan jenis dalam modal dengan pencarian berdasarkan nama, tanggal, alasan, durasi kalender, dan pratinjau alur persetujuan. Cuti Tahunan otomatis dipilih saat formulir dibuka jika tersedia untuk tahun berjalan. Modal menampilkan kuota dan langsung menutup setelah jenis dipilih. Tahun kuota mengikuti tanggal mulai pengajuan.
- **Detail pengajuan**: status, alasan, urutan persetujuan, dan catatan setiap tingkat.
- **Batalkan cuti**: tombol di detail pengajuan milik sendiri yang masih berstatus **Menunggu**, dengan konfirmasi sebelum pembatalan. Status menjadi **Dibatalkan**, saldo tahunan kembali tersedia, dan tingkat persetujuan yang belum diproses dihentikan. Riwayat serta persetujuan sebelumnya tetap tersimpan. Pengajuan yang sudah disetujui atau ditolak tidak dapat dibatalkan. Filter **Dibatalkan** tersedia di mobile dan admin-web.

Akses memakai permission `mobile.access` dan verifikasi email yang sudah berlaku; pengguna tidak memerlukan akses admin. Pengajuan dan keputusan menggunakan `CutiService` yang sama dengan admin-web, termasuk validasi kuota, tanggal, tumpang tindih, dan identitas approver dalam transaksi. Tidak ada migrasi baru.

## Jadwal dan presensi saat cuti

Setelah seluruh approver menyetujui pengajuan, jadwal pada beranda dan Layanan Presensi otomatis tampil **CUTI** tanpa jam kerja selama rentang tanggal cuti, termasuk tanggal mulai dan selesai. Pengajuan yang masih menunggu, ditolak, atau dibatalkan tidak mengubah jadwal. Setelah periode cuti berakhir, jadwal menggunakan shift pegawai kembali. Ketentuan ini juga berlaku pada cuti yang telah disetujui sebelum pembaruan, tanpa migrasi atau pengisian ulang data.

Tombol clock-in dan clock-out dinonaktifkan selama cuti. Halaman langsung dan pengiriman presensi melalui mobile/PWA maupun API juga ditolak oleh server, termasuk jika pegawai sudah clock-in sebelum cutinya disetujui. Pemeriksaan mencakup tanggal hari ini dan tanggal presensi untuk shift malam yang melewati tengah malam. Persetujuan terakhir dan penyimpanan presensi menggunakan penguncian pegawai yang sama, dengan pemeriksaan ulang sebelum penyimpanan.

Setelah memperbarui kode, jalankan `npm run build` dan muat ulang aplikasi. Perubahan ini tidak memerlukan APK baru. Pemberian hak cuti dan konfigurasi approval line tetap dilakukan melalui admin-web. Akun yang belum dikonfigurasi mendapatkan penjelasan di halaman Cuti.

Verifikasi:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Absensi/AbsensiMobile/CutiPageTest.php tests/Feature/ApprovalLineCutiTest.php tests/Feature/Absensi/AdminWeb/MasterData/MasterCutiManagementTest.php
npm run build
```
