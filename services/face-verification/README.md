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

Clock-in dan clock-out wajib lolos keputusan jarak bawaan model DeepFace. `FACE_SERVICE_MIN_SIMILARITY=40` menjadi batas confidence tambahan; nilai di bawah 40 tetap dibatasi ke 40 oleh Laravel dan FastAPI. Confidence bukan ukuran akurasi sistem pada pegawai RSARSY. `FACE_SERVICE_DISTANCE_THRESHOLD` dapat memperketat batas jarak, tetapi tidak dapat melonggarkan keputusan bawaan model. Laravel dan FastAPI harus memakai nilai konfigurasi yang sama. Kalibrasi batas yang lebih ketat menggunakan pasangan foto pegawai yang sama dan berbeda.

Sebelum menyimpan atau mengganti foto referensi, Laravel memanggil `/api/v1/profile/validate`. Foto unggahan wajib memuat tepat satu wajah, memiliki dimensi minimal 480 x 480, serta lolos pemeriksaan ukuran, ketajaman, dan pencahayaan area wajah. Foto web disesuaikan otomatis sebelum diunggah: foto kecil diperbesar secukupnya, foto besar diperkecil, dan rasio gambar tetap dipertahankan tanpa pemotongan. Sisi terpanjang dibatasi 1024 piksel; gambar sangat sempit diberi ruang putih agar dimensi unggahan tetap memenuhi batas minimum. Resize tidak menambah detail wajah sehingga foto buram tetap dapat ditolak oleh pemeriksaan kualitas. Jika validasi gagal, respons tidak valid, atau layanan tidak tersedia, foto lama tetap dipertahankan.

Pengaturan kualitas referensi memakai `FACE_SERVICE_PROFILE_MIN_IMAGE_DIMENSION=480`, `FACE_SERVICE_PROFILE_MIN_FACE_DIMENSION=160`, `FACE_SERVICE_PROFILE_MIN_BLUR_VARIANCE=50`, `FACE_SERVICE_PROFILE_MIN_BRIGHTNESS=40`, dan `FACE_SERVICE_PROFILE_MAX_BRIGHTNESS=215`. Ukuran area wajah dihitung sesudah resize inferensi; ketajaman (varians Laplacian) dan rata-rata intensitas abu-abu diukur pada area wajah berukuran 160 x 160. Nilai ini adalah pemeriksaan kualitas awal dan perlu dievaluasi menggunakan foto serta perangkat pegawai. Anti-spoofing tetap diterapkan pada foto presensi, bukan pada foto profil referensi.

Detektor utama memakai `FACE_SERVICE_DETECTOR_BACKEND=yunet`, dengan `FACE_SERVICE_DETECTOR_FALLBACK_BACKEND=opencv` sebagai cadangan. YuNet menyediakan deteksi wajah dan landmark mata untuk alignment melalui [integrasi DeepFace](https://github.com/serengil/deepface/blob/v0.0.100/deepface/models/face_detection/YuNet.py). Cadangan hanya dicoba bila wajah tidak ditemukan; beberapa wajah, kegagalan anti-spoofing, dan error mesin tetap ditolak tanpa mencoba meloloskannya lewat detektor lain. Kosongkan konfigurasi cadangan untuk menonaktifkannya. Detektor `skip` tidak boleh digunakan untuk ekstraksi wajah.

Saat memakai helm, buka kaca sepenuhnya, arahkan wajah ke kamera dan cahaya, serta pastikan mata, hidung, dan mulut terlihat. Helm yang menutupi bagian utama wajah tetap dapat menghalangi verifikasi. Uji foto pegawai yang sama dan berbeda, dengan dan tanpa helm, sebelum penggunaan produksi.

Model pengenalan, detector, dan anti-spoofing DeepFace dimuat saat startup dan disimpan di `DEEPFACE_HOME/.deepface/weights`. Startup pertama karena itu membutuhkan akses internet dan dapat berlangsung beberapa menit.

Perbarui dan mulai ulang FastAPI terlebih dahulu agar endpoint validasi profil tersedia, kemudian terapkan perubahan Laravel. Jalankan `php artisan config:clear` dan `php artisan queue:restart` dari root. Untuk Docker, rebuild container service. Jalankan `npm run build` untuk memperbarui pengolahan foto dan petunjuk pada Vue utama; perubahan ini tidak memerlukan APK baru. Foto referensi lama tidak diperiksa ulang secara massal; unggah ulang foto tersebut untuk menjalankan pemeriksaan kualitas baru.

Untuk menjaga waktu respons, gambar yang lolos validasi diperkecil khusus untuk inferensi sesuai `FACE_SERVICE_INFERENCE_MAX_DIMENSION` (default `1280`) dan embedding foto profil disimpan dalam cache LRU memori. `FACE_SERVICE_PROFILE_EMBEDDING_CACHE_SIZE` menentukan jumlah foto profil yang dicache; isi cache otomatis gugur saat service dimulai ulang dan berubah otomatis ketika isi foto profil berubah.

Untuk membuat token acak di server Linux:

```bash
openssl rand -hex 32
```

Masukkan hasilnya sebagai `FACE_SERVICE_TOKEN` pada `.env`, tanpa menuliskannya ke log atau source control.

## Menjalankan dengan Docker

Dari root proyek:

```bash
docker compose -f compose.face-verification.yml up -d --build
```

Port hanya dipublikasikan ke `127.0.0.1:8001`. Jangan mengekspos service ini langsung ke internet.

## Endpoint

- `GET /api/v1/health/live`: pemeriksaan proses, tanpa token.
- `GET /api/v1/health/ready`: kesiapan service dan status model, tanpa token.
- `POST /api/v1/verify`: multipart `profile_photo` dan `clock_in_photo`, wajib header `Authorization: Bearer <token>`.
- `POST /api/v1/profile/validate`: multipart `profile_photo`, wajib token yang sama. Mengembalikan `{"valid": true}` setelah pemeriksaan kualitas dan ekstraksi embedding berhasil, atau error `invalid_profile_photo` / `service_unavailable`.

## Pengujian

```powershell
.\.venv\Scripts\python.exe -m pytest
```

DeepFace membungkus beberapa model dan detector dengan lisensi yang berbeda. Verifikasi lisensi model yang dipilih sebelum penggunaan produksi.
