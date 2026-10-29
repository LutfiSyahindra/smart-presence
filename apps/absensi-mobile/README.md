# RSARSY Presence Android

## APK release untuk pegawai

Domain release saat ini adalah `https://absensi.rsarsylamongan.com`, disimpan
di `production.json`. Dari root repository, jalankan:

```powershell
npm run mobile:apk:release
```

Hasil build berada di `artifacts/android/arsy-presensi-build-{versionCode}.apk`.
Unggah melalui **admin-web → Settings → Versi Aplikasi**, uji APK draf, lalu
terbitkan agar halaman instalasi dan pemberitahuan pembaruan memakai rilis tersebut.
Build tidak otomatis mengganti APK publik. Panduan lengkap ada di
[pengelolaan rilis APK](../../docs/mobile-app-releases.md).
Mulai build 3 (1.2.0), modal pembaruan Android mengunduh APK di dalam aplikasi dan
langsung membuka konfirmasi pemasangan Android. Pengguna tidak perlu mencari file
di folder Download. APK lama perlu memasang build ini sekali melalui jalur unduhan
sebelumnya untuk mengaktifkan pembaruan berikutnya dari dalam aplikasi.
Build release ditandatangani memakai keystore khusus, tanpa debug mode dan
tanpa HTTP. Kunci signing dan password dibuat sekali di
`apps/absensi-mobile/.tooling/signing/`; **backup seluruh direktori tersebut**
agar APK berikutnya tetap bisa memperbarui instalasi pegawai. Direktori ini
diabaikan Git dan tidak disertakan dalam APK. Untuk rilis berikutnya, naikkan
`versionCode`/`versionName` di `android/app/build.gradle` sesuai kebutuhan.

Wrapper release memuat frontend Laravel melalui jaringan HTTPS Android,
memakai origin bridge yang sama persis dengan domain production. Ia tidak
memakai `server.url` atau daftar `allowNavigation` development. Hanya halaman
cadangan `/native/` yang dilayani dari asset APK; request service worker tetap
menuju server. GPS native/anti-mock, push dan navigasi kembali tetap tersedia.
Android System WebView yang mendukung document-start scripts serta origin-bound
message listeners diperlukan; versi lama menampilkan permintaan pembaruan.

DNS tidak perlu aktif ketika membangun APK. Sebelum dipakai pegawai, arahkan DNS
ke server, aktifkan sertifikat HTTPS valid, dan jalankan backend. Selama server
belum dapat dihubungi, APK menampilkan halaman cadangan dengan tombol coba lagi.
Perubahan tampilan/backend cukup dideploy di server; perubahan native atau
domain memerlukan APK baru. Signing/build sukses tidak menggantikan pengujian
login, kamera, GPS, serta push terhadap server production yang sudah aktif.

Perintah Android lainnya di bawah ini tetap memakai build development.

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

## Navigasi kembali Android, PWA, dan iOS

### Pembaruan data dan pengajuan selesai

Seluruh halaman mobile menggunakan WebSocket untuk mengambil perubahan data
aplikasi, termasuk perubahan langsung oleh admin seperti penugasan shift,
profil, lokasi, saldo, presensi, pengajuan, dan notifikasi. Polling cadangan
berjalan setiap 60 detik saat kanal tersambung atau 10 detik saat terputus.
Data juga disegarkan saat kembali dari latar belakang, koneksi pulih, push
diterima, atau halaman dipulihkan lewat tombol/gestur kembali.
Inbox mengambil ulang status baca dan jumlah notifikasi saat kembali dari detail.

Pembaruan mempertahankan posisi gulir, filter, isian formulir, dan catatan
persetujuan. Detail yang sedang terbuka mengikuti status terbaru; detail yang
sudah ditutup tidak dibuka lagi oleh pembaruan berkala. Pembaruan menunggu
pengiriman pengajuan selesai. Pengambilan GPS/verifikasi menunda pembaruan
hingga selesai; token bukti lokasi dipertahankan. Tidak ada penyegaran halaman
saat offline. Formulir lembur mempertahankan isian ketika data server diperbarui.

Pengiriman cuti, perubahan shift, lembur, dan penggunaan waktu lembur mengganti
entri riwayat formulir. Tombol kembali tidak memulihkan formulir pengajuan yang
sudah berhasil dikirim. Pada Cuti, tombol kembali Android menutup konfirmasi,
detail, atau panel aktif lebih dahulu.

Konfigurasi layanan Reverb dan langkah deploy tersedia pada
[panduan sinkronisasi realtime](../../docs/mobile-realtime.md).
Tidak diperlukan perubahan native atau APK baru. Uji regresi tambahan:
`node --test tests/frontend/mobile-refresh.test.mjs tests/frontend/mobile-cuti-state.test.mjs`.

### Perilaku tombol kembali

Frontend utama memakai riwayat Inertia yang sama di semua platform. Tombol kembali
di header menuju halaman mobile sebelumnya, termasuk filter/query asal. Tautan
yang dibuka langsung tanpa riwayat mobile kembali ke beranda; halaman persetujuan
cuti memiliki fallback ke Inbox. Navigasi fallback mengganti halaman agar tidak
membuat siklus kembali. Riwayat juga dipertahankan saat reload dan direset saat
logout/pergantian akun.

Wrapper Android menangani tombol kembali serta gestur sistem melalui AndroidX
`OnBackPressedDispatcher`. Pada Clock In/Out, dialog lokasi ditutup lebih dahulu,
atau tahap kamera kembali ke tahap lokasi sambil mematikan kamera. Saat verifikasi
sedang dikirim/diperiksa, tombol kembali menunggu proses tersebut selesai. Setelah
presensi berhasil, tombol kembali langsung menuju beranda untuk Clock In maupun
Clock Out. Pengiriman presensi mengganti entri GPS/kamera dengan halaman proses
atau hasil; tautan keluar dari halaman berhasil juga mengganti entri hasil.
Di beranda, tombol kembali memindahkan aplikasi ke latar belakang, termasuk setelah
menyelesaikan presensi. Perbaikan alur presensi ini cukup dengan build frontend
server, tanpa APK baru. Dukungan tombol/gestur native memerlukan APK yang sudah
memakai `OnBackPressedDispatcher` di atas.

PWA memakai riwayat browser untuk tombol/gestur sistem serta tombol kembali di
header. Wrapper iOS mengaktifkan gestur geser kembali bawaan `WKWebView`, untuk
peluncuran melalui scene maupun storyboard. Build dan uji native iOS tetap
memerlukan macOS/Xcode serta perangkat/simulator Apple.

Tes regresi frontend dari root proyek:

```powershell
node --test tests/frontend/mobile-navigation.test.mjs
```

Uji manual: buka Beranda → Presensi → Clock In/Out, lalu kembali bertahap;
ulangi dari Inbox → persetujuan cuti dengan filter aktif, sesudah reload, dan dari
tautan langsung. Pada iOS, ulangi dengan geser kembali dari tepi kiri layar.

## Log Daftar Absensi

Menu **Layanan Presensi → Log Daftar Absensi** membuka rekap kehadiran bulanan
di `/absensi/mobile/presensi/log`. Akses cepat **Riwayat** di beranda menuju
halaman yang sama. Fitur ini memakai frontend bersama untuk PWA, Android, dan iOS.

Pilih bulan/tahun untuk melihat sepuluh indikator ringkasan dan seluruh tanggal
bulan tersebut. Daftar dapat difilter ke Hadir, Perlu perhatian, atau Libur & cuti;
ketuk tanggal untuk melihat jadwal, clock-in/out, lokasi, durasi, dan catatan.
Sabtu/Minggu yang diliburkan shift ditandai **Libur**, tanggal libur nasional
ditandai **Cuti nasional** beserta nama hari rayanya, dan cuti bersama ditandai
**Cuti bersama**. Kalender memakai data lokal di Master Data → Shift; tidak
memanggil API luar saat membuka log. Jika libur nasional bertepatan dengan akhir
pekan, label Cuti nasional didahulukan. Ketiganya masuk rekap Hari libur dan filter
Libur & cuti tanpa dihitung absen. Pengaturan libur shift tetap berlaku; rekaman
clock-in/out dan cuti pribadi yang sudah disetujui tetap ditampilkan.
Header rata tengah berada di luar area isi yang digulir. Tombol kembali Android
menutup pemilih periode/detail sebelum kembali ke halaman sebelumnya.

Rekap hanya memakai data pegawai yang sedang login. Hari mendatang dan shift
yang belum selesai tidak dihitung absen/tidak clock-out. Shift malam mengikuti
tanggal mulai shift; rekaman memakai shift tersimpan, sedangkan tanggal tanpa
rekaman mengikuti shift saat ini sejak tanggal penugasan dan mulai bekerja.
Rekap membaca data tanpa membuat atau mengubah catatan presensi.

Pengujian backend: `tests/Feature/Absensi/AbsensiMobile/AttendanceLogTest.php`.

## Halaman Lembur

Akses cepat **Lembur** di beranda membuka `/absensi/mobile/lembur` pada frontend
bersama PWA, Android, dan iOS. Halaman memakai ringkasan durasi, pemilih bulan/tahun,
filter status, dan ilustrasi saat belum ada pengajuan. Header rata tengah dan tombol
Ajukan berada di luar area konten yang digulir. Tombol kembali Android menutup
dialog aktif sebelum kembali ke halaman sebelumnya.

Tombol **Ajukan** membuka `/absensi/mobile/lembur/ajukan`. Formulir berisi tanggal,
shift, durasi sebelum/setelah shift, istirahat masing-masing bagian, preferensi
kompensasi (uang lembur/cuti pengganti), alasan maksimal 200 karakter, serta maksimal
5 lampiran berukuran 10 MB per file. Format lampiran: PDF, JPG, JPEG, PNG, XLSX, XLS,
DOCX, DOC, CSV, TXT, PPT, dan PPTX. Waktu mengikuti WIB; tanggal mengacu pada awal
shift. Ringkasan langsung memperlihatkan durasi bersih setelah dikurangi istirahat.
Jumlah durasi shift dan lembur dibatasi 24 jam. Slot yang bertumpang tindih dengan
pengajuan menunggu/disetujui ditolak, termasuk slot yang melewati tengah malam.

Pengajuan tersimpan di database. Server mengambil **approval line bertipe lembur**
untuk jabatan pegawai dan menyimpan salinan nama serta urutan approver. Perubahan
approval line berikutnya tidak mengubah pengajuan lama. Approver harus memiliki
email terverifikasi dan akses `mobile.access`; unit/jabatan pemohon harus aktif.
Pemohon tidak dapat menjadi approver pengajuannya sendiri. Keputusan hanya dapat
diambil oleh approver pada tingkat berjalan, termasuk jika aktornya super-admin.
Penolakan memerlukan catatan; pembatalan hanya untuk pemohon saat status menunggu.

Approver membuka **Inbox → Butuh persetujuan → Lembur** atau tab **Persetujuan**
di halaman lembur. Delegasi menampilkan seluruh periode secara default. Notifikasi
dikirim kepada approver yang sedang mendapat giliran; hasil akhir dikirim kepada
pemohon dan approver terkait. Lampiran tersimpan di disk privat, dibuka melalui
endpoint yang memeriksa akses pemohon/approver. Tombol pratinjau tersedia pada form
pengajuan dan detail pengajuan/persetujuan untuk PDF, JPG, JPEG, PNG, TXT, dan CSV.
PDF dirender di aplikasi dengan navigasi halaman, termasuk pada WebView ponsel.
Pratinjau file yang belum dikirim menggunakan file lokal tanpa mengunggahnya.
Format Office tetap dapat dilampirkan dan diunduh, tetapi belum mendukung pratinjau.
Unduhan merupakan tindakan terpisah pada panel pratinjau. Total ringkasan tidak dipengaruhi
filter status atau pagination. Pilihan kompensasi tersimpan untuk persetujuan;
pengajuan lembur tidak otomatis menghitung payroll atau mengubah saldo cuti tahunan.

### Penggunaan waktu lembur

Kartu **Saldo waktu lembur → Gunakan** membuka `/absensi/mobile/lembur/gunakan`
pada frontend bersama PWA, Android, dan iOS. Saldo berasal dari durasi bersih
lembur berkompensasi **Cuti pengganti** yang sudah disetujui seluruh approver dan
waktu lemburnya sudah selesai. Lembur dengan kompensasi uang tidak dikreditkan.
Saldo mencakup seluruh periode dan terpisah dari ringkasan lembur bulanan.

Pilih **Telat masuk** atau **Pulang cepat**, tanggal hari ini/mendatang, durasi
dalam menit (tersedia pilihan cepat 30/60/90/120 menit), serta alasan. Shift
mengikuti penugasan pegawai; server memeriksa ulang jadwal. Form menampilkan batas
jam masuk/pulang dalam WIB, termasuk shift malam yang berakhir esok hari.
Penggunaan tidak boleh mencakup seluruh shift, jatuh pada hari libur/cuti yang
disetujui, atau menggandakan keperluan yang sama pada tanggal yang sama.

Server memakai **approval line lembur** untuk jabatan pegawai dan menyimpan
salinan urutan approver saat pengajuan. Persetujuan, notifikasi, penolakan, dan
pembatalan mengikuti alur lembur, tersedia melalui mobile maupun inbox admin web.
Sumber saldo dialokasikan dari lembur tertua dan ditampilkan dalam detail.
Transaksi serta penguncian pegawai/sumber saldo mencegah pengajuan bersamaan
menghabiskan saldo yang sama. Pengajuan menunggu mencadangkan saldo; persetujuan
akhir mengubahnya menjadi terpakai. Penolakan atau pembatalan mengembalikan saldo
tanpa menghapus riwayat alokasi. Penggunaan yang sudah disetujui tidak dapat dibatalkan.

Hanya penggunaan **disetujui** yang menggeser batas keterlambatan/pulang cepat di
presensi, log mobile, dan rekap/filter admin, sesuai tanggal dan jadwal shift yang
diajukan. Contoh shift 08.00–16.00: izin telat 60 menit memberi batas 09.00;
clock-in 09.15 tetap dihitung terlambat 15 menit. Izin pulang cepat 60 menit memberi
batas 15.00. Rekaman jam clock-in/out asli tetap tersimpan. Saldo dipakai sesuai
durasi yang disetujui, tanpa pengembalian otomatis berdasarkan jam clock aktual.

Jadwal pegawai di Beranda, Presensi, Clock In/Out, halaman berhasil, log bulanan,
rekap admin, serta API mobile menampilkan jam yang telah disesuaikan. Telat masuk
60 menit mengubah shift 08.00–16.00 menjadi 09.00–16.00 pada tanggal pengajuan;
pulang cepat 60 menit mengubahnya menjadi 08.00–15.00. Catatan penggunaan lembur
yang disetujui ditampilkan bersama jadwal. Approval sebagian/menunggu belum
mengubah jadwal. Penyesuaian hanya berlaku untuk pegawai dan tanggal pengajuan,
tanpa mengubah master shift atau jadwal pegawai lain.

Jika izin telat pada shift malam melewati tengah malam (misalnya 22.00 ditambah
180 menit menjadi 01.00 esok hari), clock-in tetap mengikuti tanggal awal shift.
Jam aktual saat clock-in tetap dicatat; keterlambatan dihitung dari jam masuk baru.

Migrasi tambahan: `2026_10_23_000001_add_lembur_time_usage.php`. Pengujian:
`tests/Feature/Absensi/AbsensiMobile/LemburUsageTest.php` dan
`node --test tests/frontend/lembur-usage.test.mjs`.

Migrasi: `php artisan migrate`. PHP/server perlu menerima unggahan gabungan minimal
50 MB agar lima file 10 MB dapat dikirim sekaligus. Frontend digunakan bersama oleh
PWA, Android, dan iOS; perubahan ini tidak memerlukan APK baru.

Tes halaman/alur: `tests/Feature/Absensi/AbsensiMobile/LemburPageTest.php` dan
`tests/Feature/Absensi/AbsensiMobile/LemburRequestTest.php`. Pada PHP CLI lokal yang
belum mengaktifkan SQLite, jalankan PHPUnit langsung dengan
`php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit`.

## Pengambilan lokasi PWA, Android, dan iOS

Clock In/Out memakai lokasi baru dengan batas akurasi dari server (default
±100 m). PWA, Safari/iOS, dan WKWebView iOS meminta pembaruan presisi dan
mode biasa secara bersamaan agar hasil berbasis Wi-Fi/jaringan dapat membantu
ketika sinyal satelit sulit didapat. Pencarian diberi waktu hingga 25 detik,
tetapi langsung selesai jika akurasi sudah mencapai ±25 m. Hasil yang sudah
memenuhi batas server diberi kesempatan 1,5 detik untuk membaik, lalu hasil
terbaik digunakan tanpa menunggu satelit. Angka akurasi perangkat ditampilkan
selama pencarian; batas radius dan akurasi server tetap berlaku.

Mulai build 6 (1.2.3), wrapper Android meminta pembaruan dari seluruh provider aktif selain passive
(GPS, network, dan fused jika tersedia). Hasil harus baru sejak permintaan
dimulai, memenuhi batas akurasi server, dan lolos pemeriksaan mock. GPS satelit
tidak lagi menjadi satu-satunya sumber yang diterima. Pencarian dihentikan saat
selesai, pindah halaman, atau dibatalkan, termasuk permintaan native yang masih
menunggu. Ini memerlukan APK baru; frontend PWA/iOS cukup dibangun dan dideploy.

Aktifkan layanan lokasi, izin lokasi presisi, dan Wi-Fi/pemindaian Wi-Fi untuk
membantu pengambilan lokasi di dalam gedung. Hasil tetap bergantung pada
perangkat dan ketersediaan layanan lokasi OS; aplikasi tidak membuat koordinat
pengganti ketika perangkat belum menghasilkan lokasi yang cukup akurat.

Uji regresi: `node --test tests/frontend/attendance-location.test.mjs tests/frontend/android-attendance-location.test.mjs`.
Uji perangkat: Clock In/Out di dalam gedung dan di luar, GPS lambat tetapi
Wi-Fi aktif, izin ditolak/dicabut, lokasi perkiraan, layanan lokasi mati,
keluar-masuk halaman saat pencarian, dan kembali dari latar belakang. Ulangi
pada PWA Android, Safari/PWA iPhone, wrapper iOS, dan APK Android baru.

## Perlindungan Fake GPS

Clock-in dan clock-out pada wrapper Android menggunakan plugin native
`AttendanceLocation`, bukan koordinat dari Geolocation API WebView. Setiap sampel
harus berasal dari pembaruan lokasi OS baru; penanda `Location.isMock()` (Android 12+)
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

Notifikasi persetujuan sudah terhubung ke Web Push untuk PWA, FCM untuk Android,
dan APNs untuk iOS. Konfigurasi kredensial, izin perangkat, serta langkah uji ada
di [panduan notifikasi mobile](../../docs/mobile-notifications.md).

Wrapper **development Android** memakai `server.url` Capacitor. Gradle tetap
memblokir release APK/AAB yang masih memakai konfigurasi tersebut. Perintah
`android:apk:release` menyinkronkan konfigurasi wrapper production yang dijelaskan
di awal dokumen. Aplikasi tetap membutuhkan koneksi ke server untuk presensi.

Origin HTTPS milik sendiri dapat diuji pada debug wrapper dengan variabel proses
`CAPACITOR_PWA_URL=https://domain-anda` sebelum sinkronisasi. Gunakan origin tanpa
path, query, kredensial, atau fragmen. HTTP hanya diizinkan untuk loopback;
alamat IP LAN HTTP tidak didukung untuk kamera/GPS frontend ini.

Proyek iOS masih memerlukan macOS, Xcode, sinkronisasi konfigurasi iOS, dan pengujian
kamera/lokasi di perangkat Apple. Jalur `adb reverse` hanya berlaku untuk Android;
perintah dan verifikasi dalam dokumen ini khusus Android development.
