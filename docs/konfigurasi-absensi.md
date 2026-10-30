# Konfigurasi absensi

Halaman admin: **Settings → Konfigurasi** (`/absensi/admin-web/setting/konfigurasi`).
Jalankan `php artisan migrate` untuk membuat tabel dan permission baru.

| Pengaturan | Nilai awal |
| --- | --- |
| Pembatalan cuti disetujui | Tidak diizinkan |
| Pembatalan lembur disetujui | Tidak diizinkan |
| Pembatalan perubahan shift disetujui | Tidak diizinkan |
| Pengajuan perubahan shift setelah clock-in | Tidak diizinkan |
| Toleransi clock-in | 0 menit |
| Approval pengajuan tanggal lampau | Diizinkan |

Permission `configuration.view` memberikan akses melihat; `configuration.update`
memberikan akses menyimpan bersama permission melihat dan `admin.access`.
Super-admin mendapatkan keduanya melalui migration dan seeder.

Aturan berlaku di server untuk web dan API mobile. Pembatalan tetap hanya dapat
dilakukan oleh pemohon. Saldo cuti dihitung ulang setelah pembatalan. Lembur yang
masih menjadi sumber saldo untuk penggunaan berstatus pending/approved harus
dibebaskan dari penggunaan tersebut sebelum dibatalkan.

Pembatalan perubahan shift mengembalikan jadwal tanggal terkait ke shift asal.
Catatan clock-in, clock-out, foto, dan lokasi tetap tersimpan. Pembatalan ditolak
jika jadwal telah berubah lagi, shift asal tidak tersedia, atau ada lembur aktif
pada tanggal tersebut. Jadwal kosong yang dibuat oleh approval dapat dihapus
agar pemohon dapat mengajukan ulang.

Jika pengajuan setelah clock-in diizinkan, approval juga dapat mengubah shift
pada catatan yang sudah memiliki clock-in. Konflik cuti, lembur, dan hari libur
tetap divalidasi.

Pengajuan dianggap lampau jika tanggal mulai cuti, tanggal lembur (termasuk
penggunaan), atau tanggal perubahan shift sebelum hari ini dalam zona waktu
aplikasi (Asia/Jakarta). Aturan diperiksa pada setiap tingkat approval, termasuk
approval massal. Jika diizinkan, pengajuan lampau dapat disetujui maupun ditolak.
Jika tidak diizinkan, kedua keputusan tersebut diblokir. Tanggal keputusan tidak
menggeser periode cuti: cuti tanggal 30 Oktober yang baru disetujui pada 3 November
tetap dihitung sebagai cuti pada 30 Oktober, bukan 3 November.

Toleransi menerima 0–1440 menit dan ditambahkan setelah dispensasi datang
terlambat dari penggunaan lembur yang disetujui. Contoh: masuk 08.00, toleransi
10 menit, clock-in 08.10 tepat waktu; 08.11 terlambat 1 menit. Perhitungan menit
utuh mengikuti perilaku presensi yang ada. Pengaturan baru juga memengaruhi
riwayat, laporan, dan filter status presensi, termasuk shift lintas tengah malam.
