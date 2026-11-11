<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Pilih tanggal jadwal.',
            'date.date_format' => 'Tanggal jadwal tidak valid.',
            'shift_id.required' => 'Pilih shift untuk tanggal ini.',
            'shift_id.integer' => 'Pilihan shift tidak valid.',
            'shift_id.exists' => 'Shift yang dipilih sudah tidak tersedia.',
        ];
    }
}
