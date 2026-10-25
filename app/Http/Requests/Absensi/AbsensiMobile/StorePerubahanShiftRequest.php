<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;

class StorePerubahanShiftRequest extends FormRequest
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
            'tanggal_perubahan' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:2100-12-31'],
            'original_shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'requested_shift_id' => ['required', 'integer', 'exists:shifts,id', 'different:original_shift_id'],
            'alasan' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['tanggal_perubahan' => 'tanggal perubahan', 'original_shift_id' => 'shift saat ini',
            'requested_shift_id' => 'shift tujuan', 'alasan' => 'alasan'];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute wajib diisi.', '*.exists' => ':attribute tidak tersedia. Muat ulang halaman.',
            'tanggal_perubahan.after_or_equal' => 'Pilih tanggal hari ini atau setelahnya.',
            'tanggal_perubahan.date_format' => 'Gunakan tanggal perubahan yang valid.',
            'tanggal_perubahan.before_or_equal' => 'Tanggal perubahan maksimal 31 Desember 2100.',
            'requested_shift_id.different' => 'Pilih shift tujuan yang berbeda dari shift saat ini.',
            'alasan.max' => 'Alasan maksimal 200 karakter.',
        ];
    }
}
