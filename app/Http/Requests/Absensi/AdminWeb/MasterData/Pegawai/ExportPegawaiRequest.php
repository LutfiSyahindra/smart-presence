<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai;

use Illuminate\Foundation\Http\FormRequest;

class ExportPegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'pegawai_ids' => ['exclude_if:all,true', 'required', 'array', 'min:1'],
            'pegawai_ids.*' => ['required', 'integer', 'distinct', 'exists:pegawais,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pegawai_ids.required' => 'Pilih minimal satu pegawai yang akan diekspor.',
            'pegawai_ids.array' => 'Daftar pegawai yang dipilih tidak valid.',
            'pegawai_ids.min' => 'Pilih minimal satu pegawai yang akan diekspor.',
            'pegawai_ids.*.integer' => 'Daftar pegawai yang dipilih tidak valid.',
            'pegawai_ids.*.distinct' => 'Pegawai yang sama tidak boleh dipilih lebih dari sekali.',
            'pegawai_ids.*.exists' => 'Salah satu pegawai yang dipilih tidak ditemukan. Muat ulang daftar pegawai.',
        ];
    }
}
