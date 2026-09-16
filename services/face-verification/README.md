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

Biarkan `FACE_SERVICE_DISTANCE_THRESHOLD` tidak terdefinisi untuk memakai threshold bawaan pasangan model/metric DeepFace. Sebelum mengubahnya, kalibrasikan dengan sampel foto pegawai nyata dan uji false accept serta false reject.

Model pengenalan, detector, dan anti-spoofing DeepFace dimuat saat startup dan disimpan di `DEEPFACE_HOME/.deepface/weights`. Startup pertama karena itu membutuhkan akses internet dan dapat berlangsung beberapa menit.

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

## Pengujian

```powershell
.\.venv\Scripts\python.exe -m pytest
```

DeepFace membungkus beberapa model dan detector dengan lisensi yang berbeda. Verifikasi lisensi model yang dipilih sebelum penggunaan produksi.
