<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
            'photo.required' => 'Pilih foto wajah yang akan digunakan.',
            'photo.image' => 'File harus berupa gambar yang valid.',
            'photo.mimes' => 'Gunakan foto berformat JPG, JPEG, PNG, atau WebP.',
            'photo.extensions' => 'Gunakan foto berformat JPG, JPEG, PNG, atau WebP.',
            'photo.min' => 'Ukuran file foto terlalu kecil untuk diproses.',
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
            'photo.dimensions' => 'Resolusi foto minimal 480 x 480 dan maksimal 6000 x 6000 piksel.',
        ];
    }
}
