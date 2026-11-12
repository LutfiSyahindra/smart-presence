# Menelusuri kegagalan koneksi APK Android

APK memuat `https://absensi.rsarsylamongan.com/absensi/mobile` melalui Android
WebView. Pesan cadangan "Server belum dapat dihubungi" pada APK lama mencakup
beberapa jenis kegagalan; pesan itu sendiri belum membuktikan internet HP buruk.

## Hasil pemeriksaan 12 November 2026

- DNS A mengarah ke `36.92.31.74`; tidak ditemukan jawaban AAAA pada resolver pengujian.
- `/up` memberi HTTP 200. `/absensi/mobile` mengarahkan pengunjung tanpa sesi
  ke `/login`, yang memberi HTTP 200.
- Akses dengan TLS 1.2 dan TLS 1.3 berhasil dengan validasi sertifikat aktif.
- Sertifikat untuk domain yang benar berlaku 25 Oktober 2026 sampai
  23 Januari 2027. Server mengirim rantai YE2 → Root YE → ISRG Root X2
  yang ditandatangani ISRG Root X1.

Hasil ini menggambarkan akses dari lingkungan pengujian saat diperiksa, bukan
jaminan akses dari setiap operator atau HP. Log Laravel di workspace adalah log
lokal/testing dan belum membuktikan penyebab kegagalan pegawai di production.

## Perbaikan wrapper

Sebelumnya `onReceivedSslError` langsung membuka halaman cadangan untuk setiap
error sertifikat, termasuk font atau tile peta. Android memanggil callback ini
untuk resource tambahan juga. Sekarang resource tersebut tetap ditolak, tetapi
hanya kegagalan dokumen utama yang sedang dimuat dapat mengganti halaman aplikasi.
Pemeriksaan origin, path dan query mencegah resource/halaman lain dianggap sebagai
dokumen utama. Halaman cadangan tidak memicu perpindahan berulang ke dirinya sendiri.

Error dokumen utama dibedakan menjadi DNS (`WEB -2`), koneksi (`WEB -6`), waktu
tunggu (`WEB -8`), handshake HTTPS (`WEB -11`), dan sertifikat (`SSL 0`–`SSL 5`).
Halaman cadangan memberi petunjuk sesuai jenis error dan kode bantuan yang dapat
disampaikan kepada admin. Log native memakai tag `PresensiConnection`, tanpa
path, query, kredensial atau token akun. Respons HTTP Laravel, termasuk error
otorisasi dan validasi, tetap ditangani oleh halaman server.

Perbaikan tersedia pada APK 1.2.4 (build 7), yang perlu didistribusikan melalui
alur rilis biasa. Deploy frontend/backend saja tidak mengubah wrapper pada APK
yang sudah terpasang.

## Pemeriksaan HP yang terdampak

1. Catat versi aplikasi, merek/tipe HP, versi Android, waktu kejadian, jaringan
   yang dipakai, dan pesan/kode bantuan. Buka domain yang sama melalui Chrome
   pada HP serta jaringan yang sama dan bandingkan dengan APK.
2. Aktifkan tanggal dan waktu otomatis. Periksa pembaruan Android System WebView
   dan sistem Android. Bandingkan Wi-Fi dan data seluler untuk menemukan
   perbedaan DNS/rute atau pembatasan akses ke domain.
3. Untuk `SSL 0`, `SSL 1`, atau `SSL 4`, bandingkan waktu HP dan masa berlaku
   sertifikat. Untuk `SSL 2`/`SSL 3`, periksa hostname, rantai sertifikat lengkap
   dan trust store OS. Jangan melewati validasi sertifikat.
4. Jika tersedia HP pengujian yang sudah diizinkan untuk USB debugging, buka APK
   dan ambil log relevan dengan `adb logcat -s PresensiConnection:W`.
5. Cocokkan waktu kejadian dengan access/error log Nginx dan log Laravel production.
   Error DNS/HTTPS dapat terjadi sebelum request mencapai Laravel.

APK mendukung instalasi mulai API 24 (Android 7.0), sedangkan dukungan bawaan
ISRG Root X1 tercatat mulai Android 7.1.1. Ini kemungkinan kompatibilitas untuk
HP lama, bukan kesimpulan untuk pengguna yang belum diketahui versi Android-nya.
Server yang diperiksa sudah menyertakan cross-sign ke X1; jangan menganggap
semua Android sebelum 14 membutuhkan sertifikat X2 baru.

Rujukan: [callback error Android WebView](https://developer.android.com/reference/android/webkit/WebViewClient#onReceivedSslError(android.webkit.WebView,%20android.webkit.SslErrorHandler,%20android.net.http.SslError))
dan [kompatibilitas sertifikat Let's Encrypt](https://letsencrypt.org/docs/certificate-compatibility/).

## Pengujian

```powershell
node --test tests/frontend/android-connection-error.test.mjs tests/frontend/android-release-config.test.mjs
```

Tes native `PresensiConnectionFailureTest` memeriksa kegagalan dokumen utama,
pengabaian resource tambahan, pencegahan loop halaman cadangan dan klasifikasi
error. Jalankan melalui task unit test Android dengan konfigurasi build yang sesuai.
Uji perangkat tetap diperlukan: kegagalan DNS, sertifikat dokumen utama, sertifikat
resource tambahan, tombol coba lagi, serta akses kembali setelah koneksi pulih.
