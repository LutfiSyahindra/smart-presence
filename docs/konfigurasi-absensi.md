# Konfigurasi absensi

Halaman admin: **Settings → Konfigurasi** (`/absensi/admin-web/setting/konfigurasi`).
Jalankan `php artisan migrate` untuk membuat tabel dan permission baru.

| Pengaturan | Nilai awal |
| --- | --- |
| Pembatalan cuti disetujui | Tidak diizinkan |
| Pengajuan cuti untuk tanggal yang sudah lewat | Tidak diizinkan |
| Pembatalan lembur disetujui | Tidak diizinkan |
| Pembatalan perubahan shift disetujui | Tidak diizinkan |
| Pengajuan perubahan shift setelah clock-in | Tidak diizinkan |
| Perubahan shift pada hari H | Tidak diizinkan |
| Pengajuan perubahan shift untuk tanggal yang sudah lewat | Tidak diizinkan |
| Pengajuan perubahan shift tanpa shift default | Tidak diizinkan |
| Toleransi clock-in | 0 menit |
| Approval pengajuan tanggal lampau | Diizinkan |
| Approval diri sendiri | Tidak diizinkan |
| Cakupan Presensi Pegawai mobile | Kepala unit (unit kerja) |
| Kategori approval line Presensi Pegawai mobile | Presensi (di luar radius) |
| Role yang dapat melihat seluruh absensi pegawai | Tidak ada role dipilih |

Permission `configuration.view` memberikan akses melihat; `configuration.update`
memberikan akses menyimpan bersama permission melihat dan `admin.access`.
Super-admin mendapatkan keduanya melalui migration dan seeder.

Pada bagian **Presensi Pegawai mobile**, pilih **Tampilkan data berdasarkan**:

- **Kepala unit (unit kerja)**: pengguna dengan permission
  `mobile.presensi-pegawai.view` melihat pegawai lain dalam unit kerjanya yang aktif.
- **Approval line**: pengguna melihat pegawai dengan jabatan yang menempatkannya
  sebagai approver pada salah satu tingkat dalam **kategori approval line** yang
  dipilih. Kategori tersedia: Cuti, Lembur, Perubahan Shift, dan Presensi (di luar
  radius). Atur penugasan melalui menu Aproval Line. Cakupan dapat melintasi unit;
  akun approver tidak wajib terhubung dengan pegawai atau memiliki unit pribadi.

Pada **Role yang dapat melihat seluruh absensi pegawai**, centang satu atau beberapa
role yang diizinkan melihat seluruh pegawai lintas unit. Pengguna dengan salah satu
role terpilih mendapat cakupan seluruh pegawai, termasuk presensi sendiri, pegawai
tanpa akun/unit, dan unit yang sudah tidak aktif. Pengguna tidak memerlukan unit
pribadi atau penugasan approval line. Pilihan ini mengesampingkan kedua cakupan di
atas hanya untuk role yang dipilih. Nilai awal kosong; super-admin juga harus
dipilih secara eksplisit untuk mendapat cakupan seluruh pegawai.

Semua cakupan tetap memerlukan `mobile.access` dan `mobile.presensi-pegawai.view`.
Pada cakupan kepala unit/approval line, presensi sendiri tetap melalui Riwayat. Daftar, ringkasan, pencarian, pagination,
foto profil, dan foto presensi memakai cakupan yang sama, termasuk untuk super-admin.
Mode approval line memakai penugasan saat ini dan hanya jabatan serta unit aktif;
tidak memerlukan pengajuan atau catatan presensi sebelumnya. Tanpa penugasan yang
sesuai, menu disembunyikan dan akses ditolak. Perubahan konfigurasi atau penugasan
berlaku pada permintaan berikutnya. Tidak ada fallback ke unit saat mode approval
line dipilih. Pengaturan ini tidak mengubah alur persetujuan pengajuan.

Perubahan daftar role, keanggotaan role, atau penghapusan role berlaku pada
permintaan berikutnya, termasuk akses foto. Penggantian nama role mempertahankan
konfigurasi karena role disimpan berdasarkan ID. Role dari guard selain `web`
tidak dapat dipilih. Mengosongkan pilihan mengembalikan semua pengguna ke cakupan
kepala unit atau approval line. Migrasi
`2026_11_16_000005_add_mobile_presensi_pegawai_all_roles_to_absensi_configurations.php`
menambahkan kolom JSON `mobile_presensi_pegawai_all_role_ids`.

Jalankan `php artisan migrate` untuk menambahkan kolom
`mobile_presensi_pegawai_scope` dan `mobile_presensi_pegawai_approval_type`.
Instalasi lama tetap memakai kepala unit sebagai cakupan awal.

Aktifkan **Izinkan pengajuan perubahan shift tanpa shift default** agar pegawai
yang belum memiliki shift default tetap dapat mengajukan perubahan. Jadwal shift
harus sudah tersedia untuk pegawai tersebut pada tanggal yang dipilih. Shift asal
diambil dari jadwal tanggal itu dan diperiksa kembali saat persetujuan terakhir.
Tanggal tanpa jadwal tetap tidak dapat diajukan. Persetujuan mengubah jadwal
tanggal terkait; pembatalan yang diizinkan mengembalikan jadwal ke shift asal.

Aktifkan **Izinkan pengajuan cuti untuk tanggal yang sudah lewat** agar cuti dapat
diajukan untuk kemarin, bulan sebelumnya, atau tahun sebelumnya melalui web dan
mobile/PWA. Kuota tahunan harus tersedia untuk tahun tanggal mulai cuti; kuota,
batas durasi, urutan tanggal, dan larangan pengajuan tumpang tindih tetap berlaku.
Cuti tahunan lintas tahun harus diajukan terpisah. Pengajuan tetap memerlukan
approval dan keputusan tanggal lampau mengikuti **Izinkan persetujuan dan
penolakan pengajuan yang sudah lewat**. Periode cuti mengikuti tanggal yang
diajukan. Jalankan `php artisan migrate` untuk menambahkan kolom
`perubahan_shift_without_default` dan `cuti_past_date`; nilai awal keduanya `false`.

Pengaturan **Izinkan approval diri sendiri** berlaku untuk cuti, lembur,
penggunaan lembur, perubahan shift, dan presensi di web maupun mobile. Jika
diaktifkan, pemohon boleh tercantum pada approval line dan menyetujui atau
menolak pengajuannya sendiri hanya saat gilirannya sebagai approver. Hak akses,
urutan approval, dan aturan pengajuan lainnya tetap berlaku. Persetujuan tetap
dilakukan secara manual, termasuk pada approval massal. Jika dinonaktifkan,
pengajuan baru dengan approval line yang memuat pemohon diblokir dan pemohon
tidak dapat memproses approval dirinya pada pengajuan yang masih menunggu.
Jalankan `php artisan migrate` untuk menambahkan kolom `allow_self_approval`
pada instalasi yang sudah ada; nilai awalnya `false`.

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

Aktifkan **Izinkan pengajuan perubahan shift untuk tanggal yang sudah lewat**
untuk mengajukan perubahan shift kemarin atau tanggal sebelumnya. Pilihan tanggal
dan shift asal mengikuti jadwal pada tanggal tersebut, termasuk tanggal pada
bulan sebelumnya. Pengajuan tetap dapat dibuat ketika clock-in atau clock-out
sudah tercatat; waktu, foto, lokasi, dan bukti presensi tetap tersimpan. Pengaturan
ini terpisah dari perubahan shift pada hari H dan pengajuan setelah clock-in.
Persetujuan maupun penolakan tetap mengikuti **Izinkan persetujuan dan penolakan
pengajuan yang sudah lewat**. Shift baru hanya diterapkan setelah disetujui dan
hanya untuk tanggal yang diajukan. Konflik cuti, lembur, dan hari libur serta
larangan mengubah menjadi OFF / Libur setelah clock-in tetap berlaku.
Jalankan `php artisan migrate` untuk menambahkan kolom `perubahan_shift_past_date`
pada instalasi yang sudah ada; nilai awalnya `false`.

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
