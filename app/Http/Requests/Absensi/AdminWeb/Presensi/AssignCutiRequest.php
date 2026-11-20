<?php

namespace App\Http\Requests\Absensi\AdminWeb\Presensi;

use App\Http\Requests\Absensi\AdminWeb\Cuti\StoreCutiRequest;
use Illuminate\Validation\Rule;

class AssignCutiRequest extends StoreCutiRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pegawai_id' => ['required', 'integer', Rule::exists('pegawais', 'id')->whereNotNull('user_id')],
            // Attendance administrators can also correct historical periods.
            'tanggal_mulai' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'pegawai_id.required' => 'Pegawai wajib dipilih.',
            'pegawai_id.exists' => 'Pegawai harus sudah memiliki akun pengguna.',
            'master_cuti_id.required' => 'Pilih jenis cuti yang diberikan kepada pegawai.',
            'tanggal_mulai.after_or_equal' => 'Tanggal mulai minimal 1 Januari 1900.',
        ];
    }
}
