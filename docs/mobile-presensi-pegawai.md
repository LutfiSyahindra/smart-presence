# Presensi Pegawai di mobile

Menu **Presensi Pegawai** di Akses cepat Beranda membuka
`/absensi/mobile/presensi-pegawai`. Kepala unit atau approver dapat mencari nama/NIK/jabatan,
memilih rentang tanggal dan status, lalu melihat jam masuk/pulang, jadwal,
keterlambatan, pulang awal, lembur tercatat, lokasi, foto, dan catatan presensi.
Ringkasan mengikuti periode dan pencarian serta menghitung semua halaman.
Pegawai tanpa catatan pada periode tersebut tetap tampil pada daftar semua status
dan filter **Belum ada presensi**; ketiadaan catatan tidak otomatis berarti alfa.

Foto profil tampil di samping nama pegawai, termasuk saat belum ada presensi.
Pegawai tanpa foto profil atau foto yang gagal dimuat menggunakan inisial nama.
Foto profil berasal dari akun pegawai dan dibuka melalui endpoint privat dengan
pemeriksaan izin dan cakupan yang sama. Pembaruan foto profil mengubah URL
versinya agar daftar menampilkan foto terbaru.

## Pengaturan akses

1. Jalankan `php artisan migrate` untuk mendaftarkan permission
   `mobile.presensi-pegawai.view`.
2. Di Pengaturan Role/Pengguna, berikan permission tersebut serta `mobile.access`
   kepada kepala unit. Migrasi otomatis memberikan permission baru kepada role
   `kepala-unit` yang sudah ada dan `super-admin`. Role kepala unit baru atau
   dengan nama lain perlu diberi permission melalui pengaturan.
3. Di **Settings → Konfigurasi → Presensi Pegawai mobile**, pilih cakupan:
   - **Kepala unit (unit kerja)**: akun terhubung dengan master pegawai dan unit
     kerja aktif. Daftar mencakup seluruh pegawai lain di unit tersebut.
   - **Approval line**: pilih kategori Cuti, Lembur, Perubahan Shift, atau Presensi
     (di luar radius), lalu atur jabatan dan approver melalui menu Aproval Line.
     Semua tingkat approver mendapatkan cakupan jabatan pada kategori terpilih,
     termasuk lintas unit. Akun approver tidak wajib memiliki unit atau master pegawai.
4. Pada **Role yang dapat melihat seluruh absensi pegawai**, pilih satu atau beberapa
   role untuk akses seluruh pegawai lintas unit. Role terpilih mengesampingkan cakupan
   kepala unit/approval line, termasuk presensi sendiri, pegawai tanpa akun/unit, dan
   unit nonaktif. Akun tidak wajib memiliki unit atau master pegawai. Nilai awal
   kosong; akses mobile dan permission fitur tetap diperlukan.

Nilai awal tetap **Kepala unit (unit kerja)**. Pegawai tanpa akun atau catatan
presensi tetap dapat tampil dalam cakupan. Presensi pengguna sendiri tersedia
melalui Riwayat dan juga dalam daftar saat role mendapat akses seluruh pegawai.
Permission ini tidak diberikan otomatis kepada role `pegawai`.
Pada mode approval line, cakupan mengikuti penugasan terbaru dan jabatan/unit
aktif, bukan riwayat pengajuan. Kategori lain tidak memperluas cakupan. Tanpa
penugasan yang sesuai atau role akses seluruh pegawai, menu dan akses tidak tersedia.

Pembatasan cakupan berlaku pada daftar, ringkasan, pencarian, pagination dan endpoint
foto, termasuk saat aktornya super-admin. Parameter unit/pegawai dari klien tidak
dapat memperluas cakupan. Perpindahan unit atau pencabutan permission diterapkan
pada permintaan berikutnya. Mode kepala unit memerlukan unit pribadi yang aktif;
mode approval line memerlukan penugasan pada kategori terpilih, kecuali pengguna
memiliki salah satu role akses seluruh pegawai. Pencabutan pilihan atau keanggotaan
role langsung mengembalikan cakupan pada permintaan berikutnya, termasuk endpoint
foto. Nama role boleh berubah karena konfigurasi menyimpan ID; role yang dihapus
tidak lagi memberikan akses.
Halaman ini hanya menyediakan pembacaan; pengaturan presensi tetap melalui admin.

Frontend Laravel/Inertia dipakai bersama oleh PWA, Android, dan iOS. Bangun ulang
aset dengan `npm run build` dan deploy kode beserta migrasi; APK baru tidak diperlukan.

## Pengujian

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/Absensi/AbsensiMobile/PresensiPegawaiTest.php
```

Tes mencakup permission khusus, unit aktif, kedua mode cakupan daftar/ringkasan/foto,
percobaan manipulasi parameter, pegawai tanpa akun/catatan, filter, shift malam,
pagination, perubahan unit/jabatan/approver/kategori/role, akses seluruh pegawai,
dan pemberian permission oleh migrasi/seeder.
