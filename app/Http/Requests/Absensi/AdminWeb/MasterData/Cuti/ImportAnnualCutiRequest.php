<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class ImportAnnualCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tahun' => ['required', 'integer', 'between:2000,2100'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'tahun.required' => 'Pilih tahun cuti tahunan.',
            'tahun.integer' => 'Tahun harus berupa bilangan bulat.',
            'tahun.between' => 'Tahun harus antara 2000 sampai 2100.',
            'file.required' => 'Pilih file Excel cuti tahunan.',
            'file.mimes' => 'File harus berupa Excel .xlsx atau .xls.',
            'file.max' => 'Ukuran file Excel maksimal 5 MB.',
        ];
    }
}
