# Master Lembur dan Lembur

## Master Data > Master Lembur

Halaman master khusus untuk melihat dan mengatur saldo waktu pegawai, dengan hero dan akses **Kelola saldo via Excel**. Cari menggunakan nama/NIK atau filter unit dan jabatan. Tabel menampilkan total saldo (kredit lembur beserta penyesuaian), waktu terpakai, waktu dicadangkan, dan saldo tersedia dari semua periode.

Gunakan **Set saldo** pada pegawai untuk mengganti saldo tersedia saat ini dalam menit. Contoh: 2 jam 30 menit diisi 150. Catatan wajib diisi dan disimpan bersama nama admin, saldo sebelum/sesudah, serta waktu pengaturan. Nilai 0 mengosongkan saldo tersedia. Penggunaan yang sudah disetujui atau sedang menunggu tetap tercatat; pembatalan penggunaan mengembalikan menitnya ke saldo tersedia. Lembur baru yang disetujui dan selesai tetap menambah saldo setelah pengaturan.

Untuk mengatur melalui Excel:

1. Klik **Kelola saldo via Excel** untuk membuka modal. Seluruh proses template dan impor dilakukan di modal ini.
2. Pilih **Semua pegawai**, **Unit & jabatan** (unit, jabatan, atau keduanya), atau **Pilih pegawai** untuk memilih satu atau beberapa pegawai. Pencarian pilihan mencakup seluruh halaman dan mempertahankan pilihan sebelumnya.
3. Pada tab **Unduh template**, unduh file sesuai cakupan. Kolomnya adalah `NIK`, `Nama Pegawai`, dan `Saldo Lembur (Menit)`. Kolom saldo ditandai kuning untuk diisi.
4. Isi jumlah menit bulat untuk masing-masing pegawai. Contoh: 2 jam 30 menit diisi 150; 12 jam diisi 720. Nilai langsung disimpan sebagai menit tanpa konversi hari atau jam per hari.
5. Pada tab **Impor saldo**, pilih file `.xlsx` atau `.xls`, isi catatan bila perlu, lalu klik **Impor saldo**. Cakupan di modal berlaku untuk template maupun impor; baris pegawai di luar cakupan ditolak.

NIK menjadi kunci pencocokan; pertahankan nol di awal NIK. Nama hanya referensi. Saldo kosong dilewati, sedangkan 0 mengatur saldo tersedia menjadi nol. Impor hanya menerima kolom `Saldo Lembur (Menit)`; template dengan satuan hari ditolak dan perlu diganti dengan template terbaru.

Batas file 5 MB dan 1.000 baris pegawai. Saldo harus berupa menit bulat antara 0 dan 525600. Formula, NIK berulang/tidak dikenal/di luar cakupan, serta saldo tidak valid ditolak. Seluruh file divalidasi dan disimpan dalam satu transaksi; satu baris bermasalah membatalkan seluruh impor. Template menyertakan pegawai yang memiliki NIK, termasuk pegawai yang belum memiliki akun pengguna. Pilihan template di atas 1.000 pegawai ditolak dengan pesan untuk mempersempit cakupan, bukan dipotong diam-diam.

Saldo yang ditetapkan manual atau melalui Excel dapat digunakan dari aplikasi mobile. Penggunaan mengikuti approval line lembur dan mencadangkan saldo selama menunggu persetujuan.

Permission: `admin.access` + `master-lembur.view` untuk melihat, ditambah `master-lembur.assign` untuk set saldo, impor, dan unduh template.

## Menu Utama > Lembur

Menu Lembur tersedia di `/absensi/admin-web/lembur` dan secara awal menampilkan penggunaan saldo lembur pegawai pada bulan berjalan. Pilih tanggal mulai dan tanggal akhir lalu klik **Tampilkan** untuk melihat penggunaan dalam rentang tersebut, termasuk kedua tanggal batas. Tabel menampilkan pegawai, tanggal dan shift, waktu penggunaan, jenis penggunaan (telat masuk/pulang cepat), durasi, dan status. Ringkasan mengikuti seluruh hasil filter, termasuk data di halaman berikutnya; total durasi penggunaan hanya menghitung pengajuan yang disetujui.

Filter tambahan tersedia untuk pegawai, unit, status, dan pencarian. Pilihan **Jenis transaksi** dapat diubah ke **Pencatatan lembur** atau **Semua transaksi** untuk melihat pencatatan lembur beserta kompensasinya. Pencatatan, lampiran, detail persetujuan, dan pembatalan tetap tersedia. Persetujuan hanya dapat dilakukan approver yang sedang mendapat giliran. Pembatalan tetap mengikuti konfigurasi dan menjaga sumber saldo yang digunakan.

Klik **Detail** pada tabel untuk membuka modal tanpa berpindah halaman atau mengubah filter dan posisi tabel. Modal memuat informasi penggunaan/lembur, sumber saldo, saldo terkini, lampiran, dan progres persetujuan. Approver dapat menyetujui/menolak dan admin berizin dapat membatalkan dari modal yang sama. Detail sumber saldo juga dibuka dalam modal. Tautan detail langsung tetap tersedia dan menampilkan modal.

Permission: `admin.access` + `lembur.view`, ditambah `lembur.create` untuk mencatat atau `lembur.cancel` untuk membatalkan. Persetujuan memakai `cuti.approve` sesuai alur sebelumnya. Permission pencatatan/pembatalan lama dipindahkan ke permission riwayat saat migrasi; permission pengaturan saldo diberikan otomatis kepada `super-admin` dan dapat diberikan kepada role lain melalui menu Role.

## Instalasi dan verifikasi

Jalankan `php artisan migrate` dan `npm run build` pada instalasi lain. Migrasi menambahkan tabel penyesuaian saldo dan dukungan penggunaan saldo manual; riwayat pengajuan lama tetap dipertahankan.

Tes: `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter 'LemburManagementTest|MasterLemburBalanceTest|LemburUsageTest'`. Flag ekstensi diperlukan hanya bila SQLite belum aktif di PHP lokal.
