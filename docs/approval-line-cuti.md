# Aproval Line untuk cuti dan lembur

Modul tersedia di absensi-web:

- **Aproval Line** (`/absensi/admin-web/approval-line`): pilih kategori **Cuti** atau **Lembur**, lalu pilih satu atau beberapa jabatan pemohon untuk menggunakan urutan 1–3 approver yang sama. Setiap jabatan dapat memiliki satu alur cuti dan satu alur lembur dengan approver berbeda.
- Menu **Notifikasi** menggantikan **Pengajuan Cuti** pada navigasi admin-web, dengan submenu **Pengajuan Butuh Approval** dan **Pengajuan Disetujui**. Halaman dan layanan pengajuan cuti lama tetap tersedia untuk integrasi berikutnya.

## Notifikasi

- **Pengajuan Butuh Approval** (`/absensi/admin-web/notifikasi/butuh-approval`) hanya menampilkan pengajuan pada tingkat approval aktif milik pengguna yang sedang login. Buka **Tinjau pengajuan** untuk melihat detail, menyetujui, atau menolak dengan catatan.
- **Pengajuan Disetujui** (`/absensi/admin-web/notifikasi/disetujui`) menampilkan pengajuan yang telah disetujui seluruh approver. Pengguna melihat pengajuan sendiri atau yang pernah disetujuinya; super-admin dapat melihat seluruh pengajuan yang telah disetujui.
- Kedua daftar mendukung pencarian pegawai, jabatan, dan unit, serta paginasi. Jumlah pada kategori menghitung seluruh pengajuan yang boleh dilihat pengguna, terlepas dari pencarian.
- Approver tanpa permission `admin.access` diarahkan ke Notifikasi setelah login admin-web dan tetap tidak memperoleh akses ke modul admin lain.

## Pengaturan

Pilih tab kategori sebelum menambah approval line. Daftar, pencarian, jumlah jabatan yang sudah memiliki alur, dan pilihan jabatan mengikuti kategori aktif. Tautan `?type=lembur` membuka kategori lembur; tanpa parameter kategori, halaman membuka cuti. Kategori alur yang sudah tersimpan tetap sama saat diedit. Untuk kategori lain, buat alur baru melalui tab tersebut.

1. Pastikan akun pemohon sudah terhubung ke master pegawai dengan unit dan jabatan aktif.
2. Approver bebas dipilih dari seluruh pengguna, tanpa batasan jabatan, unit kerja, role, permission `admin.access`, atau status verifikasi email saat konfigurasi. Login tetap menggunakan verifikasi email yang berlaku pada aplikasi.
3. Pilih jabatan pemohon dan approver melalui daftar centang. Beberapa pilihan dapat dicentang dalam satu daftar tanpa membuka pilihan satu per satu. Pencarian dan tombol **Pilih semua hasil** mendukung pemilihan sekaligus; tombol **Kosongkan** menghapus seluruh pilihan pada bagian tersebut. Jabatan terpilih juga ditampilkan sebagai daftar yang dapat dihapus.
4. Pilih 1–3 approver. Urutan pilihan menentukan tingkat persetujuan; gunakan panah naik/turun untuk mengubah urutannya. Setelah tiga pengguna dipilih, pilihan lain dinonaktifkan hingga salah satu pilihan dihapus. **Pilih semua hasil** tersedia untuk approver jika seluruh hasil pencarian masih muat dalam batas tiga pengguna. Pengguna harus berbeda pada setiap tingkat.
5. Pemohon membuka Pengajuan Cuti dan mengisi tanggal serta alasan. Alur persetujuan ditampilkan sebelum dikirim.

Pengelolaan approval line dilindungi permission `approval-line.view`, `approval-line.create`, `approval-line.update`, dan `approval-line.delete`. Migrasi memberikan permission ini kepada role super-admin. Administrator dapat memberikan permission tersebut kepada role atau pengguna lain melalui modul pengaturan yang sudah ada.

Semua pengguna yang memiliki akses absensi-web dapat mengajukan cutinya sendiri. Pengguna tidak dapat mengajukan untuk pegawai lain. Pengguna yang ditunjuk sebagai approver cuti dapat login melalui `/absensi/admin-web/login` dan membuka tugas serta riwayat persetujuannya tanpa permission `admin.access`; penugasan ini tidak memberikan akses dashboard, master data, atau pengaturan admin. Identitas approver yang tersimpan menjadi penentu persetujuan.

Kategori lembur menyediakan pengaturan approval line. Pengajuan dan pemrosesan persetujuan lembur belum tersedia. Penugasan hanya pada alur lembur tidak memberikan akses ke tugas cuti, dan pengajuan cuti tetap menggunakan alur kategori cuti.

## Alur cuti

- Saat diajukan, daftar approver disalin ke pengajuan. Tingkat 1 berstatus `pending`, sedangkan tingkat berikutnya `waiting`.
- Hanya approver pada tingkat aktif yang dapat memutuskan, termasuk ketika pengguna berperan super-admin.
- Persetujuan mengaktifkan tingkat berikutnya. Status pengajuan tetap `pending` hingga tingkat terakhir menyetujui, lalu menjadi `approved`.
- Penolakan wajib memiliki alasan. Pengajuan menjadi `rejected`, dan tingkat berikutnya menjadi `skipped`.
- Pengguna tidak dapat menyetujui pengajuan sendiri. Pengajuan diblokir jika approval line memuat akun pemohon.
- Tanggal tidak boleh sebelum hari ini, tanggal selesai harus sama atau setelah tanggal mulai, dan pengajuan tidak boleh bertumpang tindih dengan cuti lain yang masih menunggu atau sudah disetujui.
- Perubahan atau penghapusan approval line hanya memengaruhi pengajuan baru. Pengajuan yang sedang berjalan tetap menggunakan salinan urutan awal.
- Perubahan jabatan pada satu alur berlaku untuk seluruh jabatan yang terpilih. Jabatan yang dikeluarkan dapat dipasang ke alur lain. Pencarian daftar alur mencakup semua jabatan dan unit yang terhubung.
- Pengguna yang masih tercantum pada konfigurasi atau menjadi approver yang belum selesai pada pengajuan aktif tidak dapat dihapus. Hak membuka tugas persetujuan tetap tersedia dari salinan pengajuan meskipun konfigurasi alurnya dihapus.

Persetujuan menggunakan transaksi dan penguncian baris pengajuan untuk mencegah pemrosesan ganda atau persetujuan yang mendahului urutan.

Modul ini menangani pengajuan dan persetujuan cuti dasar. Perhitungan durasi di halaman adalah hari kalender; saldo cuti, jenis cuti, lampiran, dan integrasi pengajuan melalui aplikasi mobile belum termasuk.

## Migrasi dan verifikasi

Penambahan kategori lembur menggunakan kolom `type` dan batas unik `jabatan_id, type` yang sudah tersedia, sehingga tidak memerlukan migrasi baru.

Migrasi `2026_10_08_000003_allow_multiple_positions_per_approval_line` memindahkan relasi jabatan lama ke tabel `approval_line_jabatan` tanpa mengubah urutan approver atau salinan pengajuan. Rollback memecah alur bersama menjadi satu alur per jabatan agar konfigurasi tetap tersimpan.

```sh
php artisan migrate
php artisan test --filter=ApprovalLine
npm run build
```

Pengujian menggunakan SQLite in-memory sesuai `phpunit.xml`, sehingga ekstensi PDO SQLite harus tersedia pada PHP CLI. Jika ekstensi sudah terpasang tetapi tidak diaktifkan pada PHP Windows, jalankan pengujian tanpa mengubah `php.ini`:

```sh
php -d extension=php_pdo_sqlite.dll -d extension=php_sqlite3.dll vendor/phpunit/phpunit/phpunit --filter=ApprovalLine
```
