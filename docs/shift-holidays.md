# Kalender libur shift

Di **Admin Web → Master Data → Shift**, setiap shift memilih libur Sabtu, Minggu,
libur nasional, dan cuti bersama secara terpisah. Dua kategori terakhir memakai
tanggal dalam kalender lokal. Presensi mobile dan API membaca kalender lokal;
tidak ada permintaan ke layanan luar saat pegawai melakukan presensi.

## Sinkronisasi kalender nasional

Pilih tahun kalender, tekan **Tampilkan**, kemudian **Sinkronkan [tahun]**.
Sinkronisasi memerlukan seluruh izin `shift.create`, `shift.update`, dan
`shift.delete`. Hasilnya menampilkan sumber data, rujukan SKB, dan waktu terakhir
berhasil disinkronkan.

Sumber bawaan adalah [Indonesian Holidays API](https://github.com/ahrulsyamil/indonesian-holidays),
layanan publik pihak ketiga dengan rujukan SKB pemerintah, bukan API resmi
pemerintah. Endpoint `GET /holidays/{year}` membedakan `national_holiday` dan
`joint_leave`; keduanya dipetakan ke kategori kalender lokal yang sesuai.
Ketersediaan tahun bergantung pada data yang sudah diterbitkan penyedia.

Sinkronisasi memperbarui dan menghapus entri dari API yang berubah pada tahun
terpilih. Entri manual tetap dipertahankan. Mengedit entri dari API menjadikannya
koreksi manual sehingga nilainya tidak ditimpa sinkronisasi berikutnya. Tanggal
yang dihapus admin dapat muncul kembali jika masih ada di sumber nasional.

Respons kosong, tanggal tidak valid, tahun tidak cocok, kategori tidak dikenal,
dan kegagalan jaringan tidak mengubah kalender atau waktu sinkronisasi terakhir.

## Menjalankan melalui CLI dan scheduler

```sh
php artisan migrate
php artisan holidays:sync 2026
php artisan holidays:sync --next-year
```

Tanpa argumen tahun, perintah memakai tahun berjalan. `--next-year` juga mengambil
tahun berikutnya. Jika salah satu tahun belum tersedia, tahun lain tetap diproses
dan perintah melaporkan kegagalan untuk tahun yang belum tersedia.

Jadwal aplikasi menjalankan `holidays:sync --next-year` setiap hari pukul **02.15
Asia/Jakarta** tanpa menjalankan dua sinkronisasi terjadwal bersamaan. Scheduler
Laravel pada server perlu aktif, misalnya melalui cron:

```cron
* * * * * cd /path/to/absensi-rsarsy && php artisan schedule:run >> /dev/null 2>&1
```

Konfigurasi opsional di `.env`:

```dotenv
HOLIDAY_API_URL=https://indonesian-holidays.ahrulsyamil.com
HOLIDAY_AUTO_SYNC=true
```

Set `HOLIDAY_AUTO_SYNC=false` untuk hanya menggunakan sinkronisasi manual.
`HOLIDAY_API_URL` harus menyediakan kontrak API yang sama. Konfigurasi URL hanya
dibaca dari lingkungan server, bukan dari input pengguna.
