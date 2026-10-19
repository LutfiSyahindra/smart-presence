<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AllocateCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['all', 'selected'])],
            'pegawai_ids' => ['required_if:scope,selected', 'array', 'max:1000'],
            'pegawai_ids.*' => ['required', 'integer', 'distinct', Rule::exists('pegawais', 'id')->whereNotNull('user_id')],
            'tahun' => ['required', 'integer', 'between:2000,2100'],
        ];
    }

    public function messages(): array
    {
        return [
            'scope.required' => 'Pilih cakupan pegawai penerima cuti.',
            'scope.in' => 'Cakupan penerima cuti tidak valid.',
            'pegawai_ids.required_if' => 'Pilih minimal satu pegawai penerima cuti.',
            'pegawai_ids.max' => 'Maksimal 1.000 pegawai dapat dipilih sekaligus.',
            'pegawai_ids.*.exists' => 'Pegawai yang dipilih harus memiliki akun user.',
            'pegawai_ids.*.distinct' => 'Pegawai yang sama tidak boleh dipilih lebih dari sekali.',
            'tahun.required' => 'Pilih tahun cuti tahunan.',
            'tahun.integer' => 'Tahun harus berupa bilangan bulat.',
            'tahun.between' => 'Tahun harus antara 2000 sampai 2100.',
        ];
    }
}
