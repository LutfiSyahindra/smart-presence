# RSARSY Presence Android

Android development membuka **frontend Laravel/Inertia/Vue yang sama dengan browser**.
Login memakai `resources/js/pages/auth/Login.vue`; beranda, akun, dan presensi memakai
`resources/js/pages/absensi/absensi-mobile`. Perubahan UI dilakukan di `resources/js`.

Folder `src/` berisi prototipe SPA dengan API `/api/mobile/v1` yang dibuat sebelumnya.
Prototipe itu masih disimpan, tetapi **tidak dipaketkan oleh perintah Android**.
`wrapper/` hanya menyediakan halaman cadangan saat koneksi server gagal.

## Menjalankan dari VS Code

Di terminal PowerShell pada folder `apps/absensi-mobile`:

```powershell
npm run services:start
npm run android:emulator
npm run android:run
```

`android:run` membangun frontend Vue utama, menyinkronkan wrapper, membangun APK debug,
menghubungkan server, memasang APK, dan membukanya pada satu emulator/HP yang online.
Jika ada lebih dari satu perangkat, tentukan serial dari `adb devices`:

```powershell
npm run android:run -- -Target emulator-5554
```

Browser dapat membuka `http://localhost:8600/login`. Android mulai pada
`http://localhost:8600/absensi/mobile`; pengguna yang belum login diarahkan Laravel
ke halaman login yang sama. Sesi, izin akses, CSRF, foto, GPS, dan verifikasi wajah
tetap ditangani oleh frontend dan backend Laravel yang ada.

`adb reverse tcp:8600 tcp:8600` menghubungkan localhost Android ke komputer.
Ini juga menjaga konteks localhost untuk API kamera dan GPS browser.
HP development memerlukan USB debugging, persetujuan koneksi ADB, dan kabel USB.
APK lokal memerlukan Laravel yang aktif; APK ini tidak menyediakan presensi offline.

## Menjalankan dari Android Studio

```powershell
npm run android:sync
npm run cap:android
```

Pilih **Use Project's SDK** jika Android Studio menanyakan lokasi SDK.
Gradle JDK menggunakan `GRADLE_LOCAL_JAVA_HOME`/JDK 21 dari konfigurasi proyek.
Nyalakan perangkat `RSARSY_API_36`, lalu jalankan:

```powershell
npm run services:start
npm run android:connect
```

Pilih konfigurasi `app` dan klik Run di Android Studio. Gunakan satu instance emulator;
emulator yang dibuka dari VS Code dan Android Studio merupakan AVD yang sama.
Jalankan `android:connect` lagi setelah emulator/HP terputus atau dimulai ulang.
Jika perangkat online tetapi jendelanya tidak terlihat, gunakan
`npm run android:emulator -- -Restart` untuk menutup instance proyek dan membuka
kembali jendela standalone pada posisi layar normal.

## Memperbarui tampilan

Edit Vue utama di `resources/js`, lalu jalankan `npm run android:sync` untuk membangun
ulang aset server. Tutup dan buka kembali aplikasi untuk mengambil tampilan terbaru.
Perubahan Vue tidak memerlukan APK baru. Perubahan konfigurasi/native wrapper
memerlukan `npm run android:run` atau Run dari Android Studio setelah sinkronisasi.

Untuk sesi Vite/HMR, jalankan `npm run dev` dari folder mobile. Script menjalankan
Vite utama dengan origin localhost dan menghubungkan port 5173 melalui `adb reverse`.
Buka `http://localhost:8600/login`, bukan port Vite, karena halaman membutuhkan Laravel.
Jalankan `android:connect` jika perangkat baru dinyalakan setelah Vite.
Mode build di atas tidak memerlukan Vite/HMR yang berjalan.

## Perlindungan Fake GPS

Clock-in dan clock-out pada wrapper Android menggunakan plugin native
`AttendanceLocation`, bukan koordinat dari Geolocation API WebView. Setiap sampel
harus berasal dari pembaruan GPS baru; penanda `Location.isMock()` (Android 12+)
atau `isFromMockProvider()` (versi sebelumnya) diperiksa sebelum menyaring akurasi.
Penanda lokasi palsu dari provider aktif lainnya juga menyebabkan penolakan.

Sebelum mengirim foto dan absensi, aplikasi meminta lokasi native baru lagi.
Jika Fake GPS terdeteksi, GPS/izin presisi tidak tersedia, atau pemeriksaan gagal,
pengiriman dibatalkan dan pengguna perlu mengambil lokasi kembali. Perubahan
lokasi yang berarti setelah verifikasi juga memerlukan verifikasi ulang.
Server mewajibkan hasil pemeriksaan pada seluruh sampel `android_native` dan
menolak setiap `is_mock=true`, termasuk sampel yang akurasinya rendah.

Perubahan native ini memerlukan APK baru; jalankan `npm run android:run` atau
`npm run android:apk`. APK lama yang belum memiliki plugin akan menolak verifikasi
Android sampai diperbarui. Tidak ada pengecualian untuk mock GPS pada debug build.

Browser/PWA biasa tidak menyediakan penanda mock lokasi Android. Field
`location_source` dan `is_mock` adalah laporan klien, bukan bukti bertanda tangan;
perlindungan ini tidak menjamin penolakan pada perangkat root, aplikasi yang
dimodifikasi, atau request API yang dibuat sendiri. Verifikasi jaringan rumah
sakit yang tersedia tetap dapat dipakai sebagai lapisan tambahan.

Pengujian frontend: `node --test tests/frontend/android-attendance-location.test.mjs`
dari root proyek. Tes backend mencakup penolakan mock GPS dan penerimaan sampel
asli pada clock-in/clock-out. Tes instrumentasi Android tersedia di
`AttendanceLocationPluginTest` untuk memeriksa flag lokasi asli/palsu.
Untuk pengujian manual di HP, pastikan absensi berhasil dengan GPS asli, lalu
aktifkan mock-location app dan pastikan clock-in/clock-out ditolak. Ulangi dengan
menyalakan Fake GPS sesudah tahap lokasi, sebelum tombol verifikasi akhir.

## Tooling dan APK

- Node 22.12+ khusus proyek; Node.js global tidak diubah.
- Android Studio, JDK 21, Android SDK API 36, Build Tools 35.0.0, Platform Tools.
- Laravel dan service wajah lokal harus siap untuk pengujian presensi lengkap.

```powershell
npm run android:doctor
npm run services:status
npm run android:apk
# Setelah selesai:
npm run services:stop
```

APK debug: `android/app/build/outputs/apk/debug/app-debug.apk`.
Opsi lama `-Development`/perintah `android:apk:dev` tetap dapat dipakai.
Script memilih JDK/SDK dari `android/.gradle/config.properties` dan
`android/local.properties`, dengan override `MOBILE_JAVA_HOME`/`MOBILE_ANDROID_HOME`.
Node, cache npm/Gradle, data AVD, dan temporary build disimpan khusus proyek.
`JAVA_HOME` dan `PATH` hanya diubah pada proses script, bukan pengaturan global.
Log service/emulator berada di `.tooling/logs`; script stop hanya menghentikan
service yang dimulai dan dicatat oleh script tersebut.

## Emulator

```powershell
npm run android:emulator:setup
npm run android:hypervisor:status
```

Emulator memerlukan virtualisasi CPU dan Windows Hypervisor Platform yang aktif.
`android:hypervisor` meminta Administrator Windows untuk mengaktifkan fitur dan
hypervisor; script tidak melakukan restart otomatis. Data AVD berada di
`.tooling/android-user-home/avd`.
Grafis default memakai GPU host dengan Vulkan dinonaktifkan. Jika driver tidak cocok,
tutup emulator lalu coba `npm run android:emulator -- -Graphics software`.
Opsi `-Software` menonaktifkan akselerasi CPU dan jauh lebih lambat.

## Produksi dan iOS

Wrapper saat ini adalah **development Android**. `server.url` Capacitor ditujukan
untuk development, sehingga Gradle memblokir release APK/AAB yang masih memakai
konfigurasi ini. Untuk distribusi, siapkan frontend di HTTPS dan wrapper produksi
yang tetap menyediakan plugin native pemeriksaan GPS. Trusted Web Activity/PWA
saja tidak menyediakan pemeriksaan mock lokasi ini. Manifest/service worker
PWA perlu diperiksa sebelum menyatakan aplikasi siap diinstal atau digunakan offline.

Origin HTTPS milik sendiri dapat diuji pada debug wrapper dengan variabel proses
`CAPACITOR_PWA_URL=https://domain-anda` sebelum sinkronisasi. Gunakan origin tanpa
path, query, kredensial, atau fragmen. HTTP hanya diizinkan untuk loopback;
alamat IP LAN HTTP tidak didukung untuk kamera/GPS frontend ini.

Proyek iOS masih memerlukan macOS, Xcode, sinkronisasi konfigurasi iOS, dan pengujian
kamera/lokasi di perangkat Apple. Jalur `adb reverse` hanya berlaku untuk Android;
perintah dan verifikasi dalam dokumen ini khusus Android development.
