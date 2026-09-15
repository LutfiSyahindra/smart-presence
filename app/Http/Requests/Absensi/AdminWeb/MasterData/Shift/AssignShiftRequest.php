<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pegawai_ids' => collect($this->input('pegawai_ids', []))
                ->map(fn (mixed $id) => filter_var($id, FILTER_VALIDATE_INT) ?: $id)
                ->all(),
            'shift_id' => $this->filled('shift_id') ? $this->integer('shift_id') : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pegawai_ids' => ['required', 'array', 'min:1', 'max:100'],
            'pegawai_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('pegawais', 'id')->where(fn ($query) => $query->whereNotNull('user_id')),
            ],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pegawai_ids.required' => 'Pilih minimal satu pegawai.',
            'pegawai_ids.min' => 'Pilih minimal satu pegawai.',
            'pegawai_ids.max' => 'Maksimal 100 pegawai dapat diproses sekaligus.',
            'pegawai_ids.*.distinct' => 'Pilihan pegawai tidak boleh duplikat.',
            'pegawai_ids.*.exists' => 'Pegawai harus tersedia dan sudah memiliki akun pengguna.',
            'shift_id.exists' => 'Shift yang dipilih tidak tersedia.',
        ];
    }
}
