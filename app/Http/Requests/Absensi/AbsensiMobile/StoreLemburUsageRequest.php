<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLemburUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['alasan' => is_string($this->alasan) ? trim($this->alasan) : $this->alasan]);
    }

    public function rules(): array
    {
        return [
            'tanggal_lembur' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:2100-12-31'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'usage_type' => ['required', Rule::in(['late_arrival', 'early_departure'])],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1439'],
            'alasan' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['tanggal_lembur' => 'tanggal penggunaan', 'shift_id' => 'shift', 'usage_type' => 'keperluan',
            'duration_minutes' => 'durasi penggunaan', 'alasan' => 'alasan'];
    }
}
