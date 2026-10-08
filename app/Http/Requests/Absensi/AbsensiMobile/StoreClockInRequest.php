<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreClockInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxAccuracy = max(10, (float) config('attendance.location.max_accuracy_meters', 100));
        $minSamples = max(2, (int) config('attendance.location.min_samples', 3));

        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:'.$maxAccuracy],
            'location_token' => ['required', 'string', 'size:64'],
            'location_source' => ['sometimes', 'required', Rule::in(['browser', 'android_native'])],
            'location_samples' => ['required', 'array', 'min:'.$minSamples, 'max:20'],
            'location_samples.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'location_samples.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_samples.*.accuracy' => ['required', 'numeric', 'min:0', 'max:5000'],
            'location_samples.*.captured_at' => ['required', 'integer', 'min:1000000000000'],
            'location_samples.*.is_mock' => ['required_if:location_source,android_native', 'boolean'],
            'outside_radius_confirmed' => ['required', 'boolean'],
            'outside_radius_note' => ['nullable', 'string', 'max:500'],
            'photo' => [
                'required',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->min('20kb')
                    ->max('5mb')
                    ->dimensions(Rule::dimensions()->minWidth(480)->minHeight(480)->maxWidth(6000)->maxHeight(6000)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'latitude.required' => 'Lokasi perangkat belum berhasil ditemukan.',
            'latitude.between' => 'Koordinat latitude tidak valid.',
            'longitude.required' => 'Lokasi perangkat belum berhasil ditemukan.',
            'longitude.between' => 'Koordinat longitude tidak valid.',
            'accuracy.required' => 'Informasi akurasi GPS belum tersedia.',
            'accuracy.max' => 'Akurasi GPS terlalu rendah. Perbarui lokasi lalu coba kembali.',
            'location_token.required' => 'Sesi verifikasi lokasi belum tersedia. Muat ulang halaman lalu coba kembali.',
            'location_token.size' => 'Sesi verifikasi lokasi tidak valid. Muat ulang halaman lalu coba kembali.',
            'location_samples.required' => 'Sampel GPS belum tersedia. Ambil lokasi kembali.',
            'location_samples.min' => 'Sampel GPS belum cukup. Ambil lokasi kembali dan tunggu sampai proses selesai.',
            'location_samples.*.latitude.between' => 'Sampel latitude GPS tidak valid.',
            'location_samples.*.longitude.between' => 'Sampel longitude GPS tidak valid.',
            'location_samples.*.accuracy.max' => 'Salah satu sampel GPS memiliki akurasi terlalu rendah.',
            'location_samples.*.captured_at.min' => 'Waktu pengambilan sampel GPS tidak valid.',
            'location_source.in' => 'Sumber verifikasi GPS tidak valid. Ambil lokasi kembali.',
            'location_samples.*.is_mock.required_if' => 'Pemeriksaan keaslian GPS Android belum tersedia. Perbarui aplikasi lalu ambil lokasi kembali.',
            'location_samples.*.is_mock.boolean' => 'Hasil pemeriksaan keaslian GPS tidak valid. Ambil lokasi kembali.',
            'outside_radius_confirmed.required' => 'Konfirmasi lokasi presensi wajib diisi.',
            'photo.required' => 'Foto wajah belum berhasil diambil otomatis. Pastikan kamera aktif lalu coba verifikasi kembali.',
            'photo.image' => 'File foto wajah tidak valid.',
            'photo.mimes' => 'Gunakan foto berformat JPG, JPEG, PNG, atau WebP.',
            'photo.extensions' => 'Gunakan foto berformat JPG, JPEG, PNG, atau WebP.',
            'photo.min' => 'Ukuran foto terlalu kecil untuk diverifikasi.',
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
            'photo.dimensions' => 'Resolusi foto minimal 480 x 480 dan maksimal 6000 x 6000 piksel.',
            'outside_radius_note.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
