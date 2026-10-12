<?php

namespace App\Http\Requests\Absensi\AdminWeb\Cuti;

use Illuminate\Foundation\Http\FormRequest;

class StoreCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_mulai' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['alasan' => is_string($this->input('alasan')) ? trim($this->input('alasan')) : $this->input('alasan')]);
    }

    public function messages(): array
    {
        return [
            'tanggal_mulai.after_or_equal' => 'Tanggal mulai tidak boleh sebelum hari ini.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'alasan.required' => 'Alasan cuti wajib diisi.',
            'alasan.max' => 'Alasan cuti maksimal 2.000 karakter.',
        ];
    }
}
