# Assign shift massal melalui Excel

Di **Admin Web → Master Data → Shift → Assign Shift**, klik tombol **Assign Batch** di header direktori pegawai. Modal memandu empat langkah berikut:

1. Pilih bulan serta tanggal awal dan akhir dalam bulan tersebut.
2. Pilih semua pegawai, filter berdasarkan unit kerja/jabatan, atau pilih pegawai tertentu. Pilihan mencakup seluruh pegawai berakun, termasuk pegawai di halaman direktori lain. Pilihan pegawai tertentu tetap tersimpan saat pencarian atau filter berubah.
3. Klik **Download template Excel**. Pada sheet **Jadwal Shift**, isi kode shift di kolom tanggal melalui dropdown. Lihat sheet **Referensi Shift** untuk kode, nama, jam kerja, dan shift OFF.
4. Simpan file Excel, tarik file ke area unggah atau klik **Pilih file Excel**, lalu klik **Import jadwal**. Gunakan periode dan pilihan pegawai yang sesuai dengan template.

Gunakan tombol **Kembali** atau indikator langkah untuk meninjau pilihan. Ringkasan periode dan jumlah pegawai terlihat di sisi kanan pada desktop, atau di atas tombol navigasi pada layar kecil. Area formulir dapat digulir sementara navigasi tetap terlihat. Jika sudah memiliki template yang sesuai, Anda dapat langsung melanjutkan dari langkah Template ke Import tanpa mengunduh ulang. Modal tertutup setelah impor berhasil, dan hasilnya muncul melalui notifikasi di halaman.

Sel kosong dilewati. Untuk menetapkan hari libur, isi kode master shift bertipe **OFF / Libur**. Jangan mengubah header, NIK, atau kolom identitas tersembunyi. Pegawai tanpa NIK tetap dapat diproses karena template menyimpan identitas pegawai. Formula tidak diizinkan.

File dapat berupa `.xlsx` atau `.xls`, maksimal 5 MB dan 5.000 pegawai. Jika jumlah pegawai lebih banyak, gunakan filter unit atau jabatan untuk membagi pekerjaan.

Impor menyimpan jadwal per tanggal pada data presensi. Di halaman **Assign Shift**, klik **Lihat jadwal** pada pegawai untuk membuka kalender lengkap satu bulan. Kalender awal menampilkan bulan jadwal terdekat mulai hari ini, atau bulan berjalan bila tidak ada jadwal mendatang. Gunakan panah atau pemilih bulan untuk melihat bulan lain. Label **Per tanggal** menunjukkan jadwal tersimpan, termasuk hasil impor; label **Default** menunjukkan tanggal yang mengikuti shift default pegawai. Kalender juga menampilkan hari libur sesuai pengaturan shift. Jadwal tetap dapat diperiksa di menu **Presensi** dengan rentang tanggal yang sama dan digunakan oleh presensi mobile pada tanggal tersebut.

Direktori **Jadwal shift pegawai** menampilkan **Hari ini** beserta nama shift, jam kerja atau alasan libur, dan sumber jadwal. **Jadwal per tanggal** diprioritaskan, termasuk hasil Assign Batch. **Mengikuti default** berarti tanggal tersebut belum memiliki jadwal khusus. Pilihan **Shift default (cadangan)** berlaku pada tanggal yang belum dijadwalkan. Contoh: default **Office** tetap tersimpan, sementara jadwal hari ini dapat menunjukkan **Malam** atau **DAYOFF** sesuai hasil batch. Filter status dan shift di direktori tetap merujuk pada shift default. Setelah kalender pegawai ditutup, direktori memuat ulang jadwal hari ini.

Untuk mengubah satu tanggal, pilih tanggal pada kalender **Jadwal shift pegawai**, pilih **Shift baru**, lalu klik **Simpan shift**. Perubahan langsung diperbarui di kalender dan hanya berlaku pada tanggal pilihan; shift default, tanggal lain, serta catatan presensi tetap tersimpan. Shift **OFF / Libur** juga dapat dipilih. Pengubahan memerlukan izin `SHIFT_UPDATE`. Tanggal yang sudah memiliki clock in/out, cuti disetujui, lembur aktif, atau pengajuan perubahan shift aktif ditandai gembok dan tidak dapat diubah. Pemeriksaan yang sama dijalankan kembali saat penyimpanan.

Seluruh isi file diperiksa sebelum disimpan. NIK/identitas yang berubah, pegawai di luar pilihan, pegawai duplikat, kode shift yang tidak dikenal, dan periode yang tidak sesuai akan menolak seluruh impor. Jadwal yang sudah memiliki clock in/out, cuti disetujui, lembur aktif, atau pengajuan perubahan shift aktif juga tidak dapat ditimpa. Catatan presensi yang sudah ada tetap tersimpan saat jadwal yang belum dimulai diperbarui.

Fitur unduh template dan impor memerlukan izin memperbarui shift (`SHIFT_UPDATE`). Tidak ada migrasi database baru untuk fitur ini.
