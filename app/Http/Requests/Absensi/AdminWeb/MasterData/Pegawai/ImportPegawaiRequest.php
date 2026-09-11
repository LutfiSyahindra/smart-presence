<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai;

use Illuminate\Foundation\Http\FormRequest;

class ImportPegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Pilih file Excel yang akan diimpor.',
            'file.file' => 'Berkas impor tidak valid.',
            'file.mimes' => 'Format file harus .xlsx atau .xls.',
            'file.max' => 'Ukuran file Excel maksimal 5 MB.',
        ];
    }
}
