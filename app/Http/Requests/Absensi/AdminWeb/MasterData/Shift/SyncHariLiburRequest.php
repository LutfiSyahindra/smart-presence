<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;

class SyncHariLiburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['year' => ['required', 'integer', 'between:1900,9999']];
    }

    public function messages(): array
    {
        return ['year.required' => 'Pilih tahun kalender yang akan disinkronkan.', 'year.integer' => 'Tahun kalender harus berupa angka.', 'year.between' => 'Tahun kalender harus berada antara 1900 dan 9999.'];
    }
}
