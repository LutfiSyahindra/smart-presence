# RSARSY Face Verification Service

Service internal FastAPI + DeepFace untuk membandingkan foto profil pegawai dengan foto clock-in. Laravel tetap menjadi satu-satunya aplikasi yang boleh memanggil endpoint verifikasi.

## Menjalankan secara lokal

Gunakan Python 3.13. TensorFlow 2.21 menyediakan wheel untuk Python 3.10-3.13, tetapi belum untuk Python 3.14. Dari direktori ini jalankan:

```powershell
py -3.13 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 1
```

Isi variabel `FACE_SERVICE_*` pada `.env` di root proyek. `FACE_SERVICE_TOKEN` harus berupa nilai acak minimal 32 karakter dan nilainya dipakai oleh Laravel serta FastAPI.

Kecocokan clock-in dan clock-out ditentukan oleh skor confidence DeepFace yang mencapai **Pengaturan > Konfigurasi > Presensi > Batas minimal similarity wajah**, dengan rentang 1-100% dan maksimal dua angka desimal. Contohnya, batas 5% menerima skor 5% ke atas dan menolak skor di bawah 5%, termasuk saat keputusan bawaan model menyatakan tidak cocok. Foto tetap harus memuat tepat satu wajah, lolos anti-spoofing, dan menghasilkan perbandingan yang valid. Nilai disimpan di database dan dikirim Laravel sebagai multipart `minimum_similarity` pada setiap verifikasi, termasuk pekerjaan antrean. Setelah versi layanan ini diterapkan, perubahan angka berlaku pada verifikasi berikutnya tanpa mengedit `.env`, membangun ulang frontend, atau memulai ulang layanan. Riwayat presensi tidak dihitung ulang. `FACE_SERVICE_MIN_SIMILARITY` menjadi nilai awal saat migrasi dan cadangan FastAPI jika permintaan tidak menyertakan batas. Nilai dari database menggantikan cadangan tersebut.

Confidence bukan ukuran akurasi sistem pada pegawai RSARSY. Nilai rendah lebih longgar dan meningkatkan kemungkinan wajah orang berbeda diterima. Keputusan bawaan model dan batas jaraknya hanya digunakan untuk perhitungan skor serta diagnosis, bukan syarat kecocokan tambahan. Konfigurasi lama `FACE_SERVICE_DISTANCE_THRESHOLD` tidak dipakai lagi dan dapat dihapus dari environment. Pemeriksaan anti-spoofing tetap berlaku. Kalibrasi batas menggunakan pasangan foto pegawai yang sama dan berbeda.

Untuk penerapan pertama pengaturan similarity ini: perbarui dan restart FastAPI terlebih dahulu (atau rebuild container), jalankan `php artisan migrate` dan `npm run build` dari root proyek, lalu `php artisan queue:restart` agar worker memuat kode baru. Setelah penerapan awal, perubahan angka cukup disimpan dari halaman konfigurasi. Tidak diperlukan APK baru.

Sebelum menyimpan atau mengganti foto referensi, Laravel memanggil `/api/v1/profile/validate`. Foto unggahan wajib memuat tepat satu wajah, memiliki dimensi minimal 480 x 480, serta lolos pemeriksaan ukuran, ketajaman, dan pencahayaan area wajah. Foto web disesuaikan otomatis sebelum diunggah: foto kecil diperbesar secukupnya, foto besar diperkecil, dan rasio gambar tetap dipertahankan tanpa pemotongan. Sisi terpanjang dibatasi 1024 piksel; gambar sangat sempit diberi ruang putih agar dimensi unggahan tetap memenuhi batas minimum. Resize tidak menambah detail wajah sehingga foto buram tetap dapat ditolak oleh pemeriksaan kualitas. Jika validasi gagal, respons tidak valid, atau layanan tidak tersedia, foto lama tetap dipertahankan.

Pengaturan kualitas referensi memakai `FACE_SERVICE_PROFILE_MIN_IMAGE_DIMENSION=480`, `FACE_SERVICE_PROFILE_MIN_FACE_DIMENSION=160`, `FACE_SERVICE_PROFILE_MIN_BLUR_VARIANCE=50`, `FACE_SERVICE_PROFILE_MIN_BRIGHTNESS=40`, dan `FACE_SERVICE_PROFILE_MAX_BRIGHTNESS=215`. Ukuran area wajah dihitung sesudah resize inferensi; ketajaman (varians Laplacian) dan rata-rata intensitas abu-abu diukur pada area wajah berukuran 160 x 160. Nilai ini adalah pemeriksaan kualitas awal dan perlu dievaluasi menggunakan foto serta perangkat pegawai. Anti-spoofing tetap diterapkan pada foto presensi, bukan pada foto profil referensi.

Detektor utama memakai `FACE_SERVICE_DETECTOR_BACKEND=yunet`, dengan `FACE_SERVICE_DETECTOR_FALLBACK_BACKEND=opencv` sebagai cadangan. YuNet menyediakan deteksi wajah dan landmark mata untuk alignment melalui [integrasi DeepFace](https://github.com/serengil/deepface/blob/v0.0.100/deepface/models/face_detection/YuNet.py). Cadangan hanya dicoba bila wajah tidak ditemukan; beberapa wajah, kegagalan anti-spoofing, dan error mesin tetap ditolak tanpa mencoba meloloskannya lewat detektor lain. Kosongkan konfigurasi cadangan untuk menonaktifkannya. Detektor `skip` tidak boleh digunakan untuk ekstraksi wajah.

Jika kedua detektor belum menemukan wajah, layanan mencoba sekali lagi dengan koreksi kontras lokal pada pencahayaan gambar (CLAHE pada kanal L di ruang warna Lab). Urutan detektor dan pemeriksaan satu wajah serta anti-spoofing tetap berlaku pada percobaan tersebut. Gambar yang sudah terdeteksi, frame polos tanpa detail, beberapa wajah, kegagalan anti-spoofing, dan error mesin tidak memicu koreksi tambahan. Foto unggahan tetap utuh; koreksi hanya dilakukan di memori untuk analisis. Validasi kualitas foto profil tetap diukur dari foto asli. Ini membantu percobaan deteksi pada cahaya tidak merata, tetapi tidak memulihkan detail foto buram atau menjamin wajah akan dikenali.

Saat memakai helm, buka kaca sepenuhnya, arahkan wajah ke kamera dan cahaya, serta pastikan mata, hidung, dan mulut terlihat. Helm yang menutupi bagian utama wajah tetap dapat menghalangi verifikasi. Uji foto pegawai yang sama dan berbeda, dengan dan tanpa helm, sebelum penggunaan produksi.

Model pengenalan, detector, dan anti-spoofing DeepFace dimuat saat startup dan disimpan di `DEEPFACE_HOME/.deepface/weights`. Startup pertama karena itu membutuhkan akses internet dan dapat berlangsung beberapa menit.

Perbarui dan mulai ulang FastAPI terlebih dahulu agar endpoint validasi profil tersedia, kemudian terapkan perubahan Laravel. Jalankan `php artisan config:clear` dan `php artisan queue:restart` dari root. Untuk Docker, rebuild container service. Jalankan `npm run build` untuk memperbarui pengolahan foto dan petunjuk pada Vue utama; perubahan ini tidak memerlukan APK baru. Foto referensi lama tidak diperiksa ulang secara massal; unggah ulang foto tersebut untuk menjalankan pemeriksaan kualitas baru.

Untuk menjaga waktu respons, gambar yang lolos validasi diperkecil khusus untuk inferensi sesuai `FACE_SERVICE_INFERENCE_MAX_DIMENSION` (default `1280`) dan embedding foto profil disimpan dalam cache LRU memori. `FACE_SERVICE_PROFILE_EMBEDDING_CACHE_SIZE` menentukan jumlah foto profil yang dicache; isi cache otomatis gugur saat service dimulai ulang dan berubah otomatis ketika isi foto profil berubah.

Untuk membuat token acak di server Linux:

```bash
openssl rand -hex 32
```

Masukkan hasilnya sebagai `FACE_SERVICE_TOKEN` pada `.env`, tanpa menuliskannya ke log atau source control.

## Pengambilan kamera dan diagnosis penolakan

Foto profil tetap menjadi satu-satunya referensi wajah; pegawai tidak perlu melakukan pendaftaran wajah tambahan. Frontend Clock In dan Clock Out yang dipakai bersama oleh Android, PWA, dan iOS mengambil empat gambar kamera selama sekitar 0,6 detik setelah tombol ditekan. Ketajaman dan pencahayaan bagian tengah gambar dipakai untuk memilih satu gambar. Ini hanya perkiraan kualitas gambar, bukan deteksi wajah atau keputusan liveness. Hanya satu foto JPEG yang dikirim ke server, dengan rasio gambar utuh, sisi terpanjang maksimal 1280 piksel, tanpa filter, pemotongan, atau pembalikan gambar. Buffer gambar sementara dilepas setelah pengambilan selesai atau dibatalkan.

Minimum confidence dan anti-spoofing tetap diterapkan. Keputusan jarak bawaan model tidak lagi menjadi syarat kecocokan tambahan. Pesan penolakan anti-spoofing menjelaskan bahwa pemeriksaan keaslian belum berhasil, karena hasil prediksi tidak membuktikan bahwa pegawai memakai tangkapan layar. Hasil anti-spoofing yang hilang/tidak valid diperlakukan sebagai gangguan layanan dan tetap tidak meloloskan presensi.

Penolakan menghasilkan log `face_verification_rejected` pada stderr service. `reason=spoof_detected` terjadi sebelum pencocokan referensi; `reason=face_mismatch` memuat `distance`, `threshold`, `confidence`, `minimum_confidence`, keputusan bawaan `model_verified`, serta `matching_policy=similarity`. `threshold` adalah batas jarak bawaan model untuk diagnosis; batas penerimaan yang berlaku adalah `minimum_confidence`. Log juga mencantumkan detector yang benar-benar dipakai, resolusi gambar, ukuran/ketajaman/pencahayaan area wajah, dan `anti_spoof_score` bila tersedia. `preprocessing=lighting_corrected` pada penolakan pencocokan menunjukkan bahwa wajah baru terdeteksi setelah koreksi pencahayaan; `lighting_retry` pada penolakan deteksi menunjukkan apakah percobaan koreksi dilakukan. Skor anti-spoofing adalah skor kelas yang dipilih model, bukan selalu probabilitas wajah asli. Tidak ada foto, embedding, token, atau identitas pegawai dalam log ini.

Untuk menerapkan perubahan, jalankan `npm run build` dari root proyek, deploy frontend/backend, restart service FastAPI (atau rebuild container), lalu `php artisan queue:restart`. Perubahan frontend bersama ini tidak memerlukan APK baru pada wrapper Android yang memuat frontend server. Validasi dengan HP Android asli tetap diperlukan: bandingkan percobaan wajah asli pada beberapa kondisi cahaya dengan foto cetak/video di layar. Tes otomatis memeriksa alur pengambilan, pembatalan, dan keputusan penolakan; tes tersebut tidak mengukur tingkat salah tolak model pada pegawai.

 ## Menjalankan dengan Docker

Dari root proyek:

```bash
docker compose -f compose.face-verification.yml up -d --build
```

Port hanya dipublikasikan ke `127.0.0.1:8001`. Jangan mengekspos service ini langsung ke internet.

## Endpoint

- `GET /api/v1/health/live`: pemeriksaan proses, tanpa token.
- `GET /api/v1/health/ready`: kesiapan service dan status model, tanpa token.
- `POST /api/v1/verify`: multipart `profile_photo`, `clock_in_photo`, serta `minimum_similarity` opsional (angka 1-100). Wajib header `Authorization: Bearer <token>`. Batas hanya berlaku pada permintaan tersebut dan tidak mengubah konfigurasi global engine.
- `POST /api/v1/profile/validate`: multipart `profile_photo`, wajib token yang sama. Mengembalikan `{"valid": true}` setelah pemeriksaan kualitas dan ekstraksi embedding berhasil, atau error `invalid_profile_photo` / `service_unavailable`.

## Pengujian

```powershell
.\.venv\Scripts\python.exe -m pytest
```

DeepFace membungkus beberapa model dan detector dengan lisensi yang berbeda. Verifikasi lisensi model yang dipilih sebelum penggunaan produksi.
