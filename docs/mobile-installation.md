# Halaman instalasi aplikasi

Pegawai menerima email aktivasi dari administrator. Setelah membuat password,
akun dan emailnya diaktifkan, lalu pegawai diarahkan ke `/` untuk memasang
aplikasi. Pesan sukses ditampilkan di halaman ini. Pendaftaran lewat `/register`
juga berakhir di halaman instalasi. Reset password akun yang sudah aktif tetap
mengarah ke halaman login.

## APK Android

Letakkan APK distribusi di `public/downloads/arsy-presensi.apk`. Tombol unduh di
landing page otomatis aktif jika file tersedia. File APK tidak disimpan di Git.
Alternatifnya, isi `MOBILE_ANDROID_APK_URL` dengan URL APK distribusi di `.env`;
URL ini diprioritaskan daripada file lokal. Perbarui cache konfigurasi dengan
`php artisan config:cache` jika deployment menggunakan cache konfigurasi.

Sebelum file atau URL disediakan, halaman menampilkan "APK belum tersedia".
APK debug di `apps/absensi-mobile/android/app/build/outputs` tidak dipublikasikan
otomatis. Gunakan APK yang dikonfigurasi untuk server distribusi; wrapper debug
yang mengarah ke localhost melalui `adb reverse` tidak dapat dipakai langsung
oleh pegawai. Lihat [panduan mobile](../apps/absensi-mobile/README.md).

## PWA iPhone

Buka landing page melalui Safari pada domain HTTPS aplikasi. Pilih Bagikan,
Tambahkan ke Layar Utama, aktifkan Buka sebagai App Web jika pilihan tersedia,
lalu Tambah. Buka ikon aplikasi dan masuk dengan akun pegawai yang sudah aktif.
Langkah ini mengikuti [panduan resmi Apple](https://support.apple.com/en-lamr/guide/iphone/iphea86e5236/ios).

Manifest tersedia pada landing page, pendaftaran, aktivasi, login, dan halaman
mobile, termasuk saat aktivasi berpindah ke landing page melalui Inertia.
`start_url` tetap `/absensi/mobile`; scope `/` menjaga halaman login tetap dalam
konteks PWA ketika pegawai belum masuk. PWA tetap memerlukan koneksi internet.

## Verifikasi

- Aktivasi dari email menampilkan pesan sukses dan halaman instalasi tanpa login otomatis.
- APK lokal/URL konfigurasi menghasilkan tautan unduh; tanpa keduanya tombol dinonaktifkan.
- iPhone: pasang dari Safari, buka ikon, login, lalu uji kamera/lokasi pada perangkat asli.
- Reset password akun aktif tetap menuju login; token aktivasi hanya berlaku sekali.
