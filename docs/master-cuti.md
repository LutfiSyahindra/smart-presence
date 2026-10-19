# Master Cuti

Menu **Master data → Master Cuti** tersedia di `/absensi/admin-web/master-data/cuti`.

## Jenis dan pemberian cuti

Tambahkan kode, nama, durasi hari, kategori, keterangan, dan status aktif. Kategori **Cuti biasa** membatasi durasi setiap pengajuan; kategori **Cuti Tahunan** memakai durasi sebagai kuota awal per tahun. Hanya satu master berkategori Cuti Tahunan yang dapat dibuat. Durasi dihitung berdasarkan hari kalender, termasuk tanggal mulai dan selesai.

Tombol **Berikan cuti** menerapkan hak cuti kepada semua pegawai dengan akun user atau pegawai yang dipilih. Semua akun pegawai dapat menerima alokasi tanpa bergantung pada role atau verifikasi email. Pilihan “seluruh” mencakup pegawai di luar hasil pencarian. Pemberian ulang tidak menggandakan alokasi dan tidak mengganti kuota tahunan yang sudah diset manual. Akun yang dibuat kemudian perlu diberi alokasi melalui menu ini.

Mengubah durasi cuti biasa memperbarui batas bagi penerima. Mengubah kuota awal cuti tahunan mempertahankan kuota penerima yang sudah ada. Jenis cuti yang pernah digunakan dalam pengajuan tidak dapat dihapus; nonaktifkan agar tidak dapat diajukan lagi. Nama jenis cuti dan durasi pengajuan disimpan sebagai riwayat saat diajukan.

## Kuota tahunan

Pilih tahun, lalu gunakan **Set tahunan** pada baris pegawai untuk pengaturan manual, atau **Set kuota pegawai** untuk pegawai terpilih maupun semua pegawai. Kuota berlaku hanya untuk tahun yang dipilih. Pegawai yang belum mendapat cuti tahunan otomatis mendapat alokasi saat kuotanya diatur.

Jumlah yang diisi adalah **kuota total**, bukan penambahan saldo atau penggantian sisa. Sisa = kuota total − cuti disetujui − pengajuan menunggu. Penolakan mengembalikan hari yang dicadangkan. Kuota dapat diisi 0–366 hari tetapi tidak boleh kurang dari jumlah disetujui dan menunggu. Pengaturan massal disimpan seluruhnya atau dibatalkan seluruhnya jika ada kesalahan.

## Excel

Unduh template dari halaman Master Cuti, isi kolom **Jumlah Cuti Tahunan**, lalu unggah file `.xlsx` atau `.xls`. File maksimal 5 MB dan 1.000 baris. Kolom template:

| Kolom | Penggunaan |
| --- | --- |
| NIK | Identitas pegawai, harus sama persis dengan master pegawai. Pertahankan nol awal. |
| Nama Pegawai | Referensi untuk pengisian; pencocokan memakai NIK. |
| Jumlah Cuti Tahunan | Kuota total tahun yang dipilih; kosong dilewati, 0 mengatur kuota menjadi nol. |

Template mengisi identitas hingga 1.000 pegawai berakun yang memiliki NIK. Gunakan beberapa file untuk pegawai tambahan. NIK yang tidak ditemukan atau tidak memiliki akun, NIK duplikat, formula, jumlah tidak valid, dan kuota di bawah pemakaian ditolak. Seluruh file divalidasi sebelum perubahan disimpan.

## Pengajuan dan akses

Pengajuan cuti yang sudah tersedia sekarang wajib memilih jenis cuti aktif yang dialokasikan kepada pegawai. Kuota tahunan mengikuti tahun tanggal mulai; pengajuan yang melewati pergantian tahun harus dipisah. Approval line dan pembatasan akses pengajuan yang sudah ada tetap berlaku. Pengajuan lama tanpa jenis cuti tetap dapat dilihat dan diproses, tetapi tidak otomatis dibebankan pada kuota tahunan karena jenisnya belum diketahui.

Pengelolaan membutuhkan `admin.access` dan permission sesuai tindakan: `master-cuti.view`, `master-cuti.create`, `master-cuti.update`, `master-cuti.delete`, atau `master-cuti.assign`. Permission `assign` mencakup pemberian cuti, pengaturan kuota, unduh template, dan impor. Migrasi menambahkan permission ke role super-admin; administrator dapat memberikan permission kepada role lain melalui menu Role.

## Validasi pengembangan

PHP lokal dapat mengaktifkan SQLite khusus saat menjalankan pengujian tanpa mengubah konfigurasi database aplikasi:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Absensi/AdminWeb/MasterData/MasterCutiManagementTest.php tests/Feature/ApprovalLineCutiTest.php tests/Feature/ApprovalLineLemburTest.php tests/Feature/Absensi/AdminWeb/Setting/AuthorizationManagementTest.php
npm run build
```
