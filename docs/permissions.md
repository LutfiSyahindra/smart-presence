# Permission web dan mobile

Halaman **Pengaturan → Permission** memiliki tab **Admin Web** dan **Absensi Mobile**. Filter platform diterapkan sebelum pencarian dan pagination. Pilihan permission pada form role dan pengguna juga dipisahkan per platform; berpindah tab tidak menghapus pilihan pada tab lain.

Permission berawalan `mobile.` masuk ke platform mobile. Permission lainnya masuk ke web, termasuk permission khusus yang sudah ada. Keduanya tetap menggunakan guard `web`.

| Platform | Fitur | Permission |
| --- | --- | --- |
| Web | Masuk portal | `admin.access` |
| Web | Dashboard | `dashboard.view` |
| Web | Inbox dan push | `notifications.view` |
| Web | Daftar, detail cuti, dan antrean approval | `cuti.view` |
| Web | Mengajukan cuti | `cuti.create` + `cuti.view` |
| Web | Keputusan dan approval massal | `cuti.approve` + `cuti.view` |
| Mobile | Beranda dan masuk aplikasi | `mobile.access` |
| Mobile | Ringkasan presensi | `mobile.attendance` |
| Mobile | Clock-in | `mobile.clock-in` + `mobile.attendance` |
| Mobile | Clock-out | `mobile.clock-out` + `mobile.attendance` |
| Mobile | Riwayat presensi | `mobile.history` |
| Mobile | Presensi pegawai dalam unit kerja sendiri | `mobile.presensi-pegawai.view` |
| Mobile | Daftar, saldo, detail cuti, dan antrean approval | `mobile.cuti.view` |
| Mobile | Mengajukan cuti | `mobile.cuti.create` + `mobile.cuti.view` |
| Mobile | Membatalkan cuti | `mobile.cuti.cancel` + `mobile.cuti.view` |
| Mobile | Keputusan dan approval massal | `mobile.cuti.approve` + `mobile.cuti.view` |
| Mobile | Inbox dan push | `mobile.notifications` |
| Mobile | Akun dan foto profil | `mobile.account` |

Semua fitur mobile memerlukan `mobile.access`. Modul administrasi web memerlukan `admin.access`. Akses tugas cuti web tetap tersedia bagi approver yang ditugaskan tanpa `admin.access`, dengan permission fitur terkait. Permission approval tetap memerlukan penugasan dan giliran approval yang sesuai.

Master data, presensi pegawai, approval line, pengguna, role, dan permission tetap memakai permission yang sudah ada. Tombol placeholder yang fiturnya belum tersedia tidak memiliki endpoint baru.

Migrasi `2026_10_21_000001_complete_portal_permissions.php` mendaftarkan permission baru, mempertahankan akses role dan permission langsung berdasarkan `admin.access`/`mobile.access`, dan mempertahankan akses approver yang sudah ditugaskan termasuk snapshot historis. Permission `mobile.account` yang telah dicabut tidak diberikan kembali. Seeder memberikan permission mobile umum kepada role `pegawai`, kecuali akses khusus `mobile.presensi-pegawai.view`; super admin tetap memiliki seluruh akses. Approver baru dapat diberi permission tugas web melalui role atau permission langsung.

Jalankan `php artisan migrate` pada lingkungan tujuan saat menerapkan perubahan. Untuk tes di PHP lokal yang ekstensi SQLite-nya belum aktif, gunakan `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit`.

Menu **Presensi Pegawai** memerlukan permission khusus `mobile.presensi-pegawai.view`
dan `mobile.access`. Migrasi `2026_11_16_000001_add_mobile_presensi_pegawai_permission.php`
memberikannya kepada role `kepala-unit` yang sudah ada dan `super-admin`. Permission
ini dikecualikan dari akses default role `pegawai` pada seeder. Cakupan diatur melalui
**Settings → Konfigurasi**: kepala unit (unit kerja aktif pengguna, nilai awal) atau
approval line (jabatan aktif yang menugaskan pengguna sebagai approver dalam kategori
terpilih, dapat lintas unit). Kedua mode mengecualikan presensi sendiri. Pengaturan
**Role yang dapat melihat seluruh absensi pegawai** memberi cakupan seluruh pegawai
lintas unit (termasuk diri sendiri) kepada pengguna dengan salah satu role terpilih.
Permission fitur dan akses mobile tetap wajib. Tidak ada role yang dipilih secara
otomatis, termasuk super-admin; lihat [panduan fitur](mobile-presensi-pegawai.md).
