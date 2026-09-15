<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai;

use Illuminate\Foundation\Http\FormRequest;

class SendBatchPegawaiInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pegawai_ids' => ['required', 'array', 'min:1', 'max:100'],
            'pegawai_ids.*' => ['required', 'integer', 'distinct', 'exists:pegawais,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pegawai_ids.required' => 'Pilih minimal satu pegawai yang akan dikirimi email aktivasi.',
            'pegawai_ids.array' => 'Daftar pegawai yang dipilih tidak valid.',
            'pegawai_ids.min' => 'Pilih minimal satu pegawai yang akan dikirimi email aktivasi.',
            'pegawai_ids.max' => 'Maksimal 100 pegawai dapat diproses dalam satu pengiriman.',
            'pegawai_ids.*.required' => 'Daftar pegawai yang dipilih tidak valid.',
            'pegawai_ids.*.integer' => 'Daftar pegawai yang dipilih tidak valid.',
            'pegawai_ids.*.distinct' => 'Pegawai yang sama tidak boleh dipilih lebih dari sekali.',
            'pegawai_ids.*.exists' => 'Salah satu pegawai yang dipilih tidak ditemukan.',
        ];
    }
}
