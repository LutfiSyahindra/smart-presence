# Perubahan Shift mobile

Menu **Perubahan Shift** di beranda membuka `/absensi/mobile/perubahan-shift`.
Halaman menggunakan frontend Laravel/Inertia/Vue bersama untuk PWA, Android,
dan iOS. Header dan tombol Ajukan berada di luar area konten yang digulir.
Panel periode, filter, formulir, dan detail menggunakan dialog dengan fokus
terkunci serta tombol kembali mobile yang menutup panel terlebih dahulu.
Isian yang belum dikirim meminta konfirmasi sebelum dihapus.

Pengajuan berisi satu tanggal perubahan (hari ini atau mendatang), shift asal,
shift tujuan yang berbeda, dan alasan maksimal 200 karakter. Tanggal mengacu
pada awal shift dan waktu mengikuti WIB. Shift malam diberi keterangan +1 hari.
Server menentukan identitas pegawai, shift asal, status, dan approver; nilai
identitas/status yang dikirim klien diabaikan. Perubahan ditolak jika salah satu
shift telah dimulai, tanggal sudah memiliki catatan presensi/cuti disetujui,
atau sudah ada pengajuan menunggu/disetujui untuk pegawai pada tanggal yang sama.
Kunci transaksi pada pegawai mencegah pengajuan ganda bersamaan.

Penyimpanan menggunakan `perubahan_shift_requests` dan
`perubahan_shift_approvals`. Pegawai memerlukan jabatan/unit aktif, shift,
serta approval line **Perubahan Shift** tersendiri dengan 1–3 approver
terverifikasi yang memiliki akses mobile atau akses persetujuan web. Pemohon tidak dapat menjadi approver
sendiri. Nama pegawai, jabatan/unit, jam shift, nama dan urutan approver disalin
saat pengajuan agar perubahan master berikutnya tidak mengubah riwayat.
Daftar Pengajuan saya menampilkan pengajuan pemilik akun, dengan filter bulan/status
dan pagination. Ringkasan periode tidak dipengaruhi filter status. Tab Untuk ditinjau
dan kategori Perubahan shift di Inbox menampilkan pengajuan menunggu giliran
approver yang sedang login, seluruh periode secara default.

Pengajuan baru mengirim notifikasi kepada approver yang mendapat giliran dan
super-admin, selain pemohon sendiri. Notifikasi tersimpan di inbox dalam transaksi
yang sama; job Web Push/PWA dan push native Android/iOS dijadwalkan setelah commit
melalui layanan pengajuan yang sudah ada. Push perangkat mengikuti konfigurasi
dan izin/subscription yang sudah diaktifkan. Klik notifikasi membuka detail terbaru
dan menandainya dibaca. Detail hanya dapat dibuka pemohon, approver yang tersimpan,
atau super-admin. Inbox admin membuka detail web tersendiri dengan perbandingan
shift dan tombol Setujui/Tolak. Daftar Butuh Approval, filter Perubahan Shift,
hitungan menu/bell, daftar Disetujui, dan persetujuan terpilih mendukung perubahan
shift. ID cuti, lembur, dan perubahan shift dikirim terpisah saat persetujuan massal.

Pemohon dapat memilih **Batalkan pengajuan** dari detail selama statusnya masih
**Menunggu**, lalu mengonfirmasi. Server memeriksa kepemilikan dan status kembali
di dalam transaksi dengan kunci baris, mencatat `cancelled_at`, dan menandai tahap
approval yang belum selesai sebagai dilewati. Approval yang sudah selesai tetap
tersimpan. Pembatalan mengirim notifikasi ke approver terkait dan super-admin,
serta mengeluarkan pengajuan dari hitungan menunggu. Pengajuan baru pada tanggal
yang sama diperbolehkan setelah pembatalan. Pembatalan tidak memerlukan approval
line/master pegawai yang masih sama, karena memakai salinan pengajuan tersimpan.

Setujui/Tolak muncul hanya bagi approver pada tahap berjalan; super-admin yang
tidak ditugaskan juga tidak dapat mengambil keputusan. Web tetap memerlukan izin
`cuti.view` dan `cuti.approve`; penugasan tidak memberikan izin admin lainnya.
Penolakan wajib memiliki catatan (maksimal 1.000 karakter), menutup tahap berikutnya,
dan memberi kabar kepada pemohon. Persetujuan awal meneruskan giliran dan notifikasi
kepada approver berikutnya. Status menjadi Disetujui setelah seluruh tahap selesai.

Persetujuan akhir membuat jadwal bertanggal pada `presensis` dengan shift tujuan
dan clock-in/out kosong. Beranda, presensi, dan log membaca shift tanggal tersebut;
shift default pegawai tidak diubah. Server memeriksa ulang ketersediaan pegawai/shift,
jam shift, tanggal yang belum dimulai, hari libur target, presensi yang sudah ada,
cuti disetujui, dan lembur aktif sebelum menerapkan jadwal. Konflik ditampilkan
sebagai kesalahan keputusan tanpa menyimpan persetujuan akhir. Penerapan jadwal,
status, langkah approver, dan inbox bersifat atomik, termasuk persetujuan massal.
Penyimpanan awal dan pembatalan tidak membuat/mengubah rekaman presensi.

Migrasi:

```powershell
php artisan migrate --path=database/migrations/2026_10_23_000002_create_perubahan_shift_requests.php
php artisan migrate --path=database/migrations/2026_10_23_000003_add_cancellation_to_perubahan_shift_requests.php
php artisan migrate --path=database/migrations/2026_10_23_000004_add_decision_to_perubahan_shift_requests.php
```

Pengujian backend:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/Absensi/AbsensiMobile/PerubahanShiftTest.php
```

Pembaruan UI cukup membangun aset utama (`npm run build`). Tidak diperlukan
perubahan wrapper native. Validasi simulator/perangkat iOS memerlukan macOS/Xcode.
