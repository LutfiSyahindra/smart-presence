<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti;

class SetAnnualCutiRequest extends AllocateCutiRequest
{
    public function rules(): array
    {
        return parent::rules() + ['jumlah_hari' => ['required', 'integer', 'between:0,366']];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'jumlah_hari.required' => 'Jumlah cuti tahunan wajib diisi.',
            'jumlah_hari.integer' => 'Jumlah cuti tahunan harus berupa bilangan bulat.',
            'jumlah_hari.between' => 'Jumlah cuti tahunan harus antara 0 sampai 366 hari.',
        ];
    }
}
