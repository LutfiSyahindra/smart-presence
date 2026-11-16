<?php

namespace App\Http\Requests\Absensi\AdminWeb\Lembur;

use App\Http\Requests\Absensi\AbsensiMobile\StoreLemburRequest;
use Illuminate\Validation\Rule;

class StoreAdminLemburRequest extends StoreLemburRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pegawai_id' => ['required', 'integer', Rule::exists('pegawais', 'id')->whereNotNull('user_id')],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'pegawai_id.required' => 'Pilih pegawai yang akan dicatat lemburnya.',
            'pegawai_id.exists' => 'Pegawai harus terhubung dengan akun pengguna.'];
    }
}
