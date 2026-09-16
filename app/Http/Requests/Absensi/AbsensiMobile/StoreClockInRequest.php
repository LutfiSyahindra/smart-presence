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
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:5000'],
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
            'outside_radius_confirmed.required' => 'Konfirmasi lokasi presensi wajib diisi.',
            'photo.required' => 'Ambil foto wajah terlebih dahulu.',
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
