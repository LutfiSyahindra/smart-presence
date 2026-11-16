<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LemburBalanceSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['all', 'organization', 'employees'])],
            'unit_id' => ['exclude_unless:scope,organization', 'nullable',
                Rule::requiredIf(fn () => $this->input('scope') === 'organization' && ! $this->filled('position_id')),
                'integer', 'exists:unit_kerjas,id'],
            'position_id' => ['exclude_unless:scope,organization', 'nullable', 'integer',
                Rule::exists('jabatans', 'id')->where(fn ($query) => $query
                    ->when($this->filled('unit_id'), fn ($query) => $query->where('unit_kerja_id', $this->input('unit_id'))))],
            'pegawai_ids' => ['exclude_unless:scope,employees', 'required', 'array', 'min:1', 'max:1000'],
            'pegawai_ids.*' => ['integer', 'distinct', 'exists:pegawais,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'scope.in' => 'Pilih cakupan pegawai yang tersedia.',
            'unit_id.required' => 'Pilih minimal satu unit kerja atau jabatan.',
            'position_id.exists' => 'Jabatan tidak sesuai dengan unit kerja yang dipilih.',
            'pegawai_ids.required' => 'Pilih minimal satu pegawai.',
            'pegawai_ids.max' => 'Maksimal 1.000 pegawai per file.',
            'pegawai_ids.*.distinct' => 'Pilihan pegawai tidak boleh berulang.',
            'pegawai_ids.*.exists' => 'Pegawai tidak ditemukan. Muat ulang daftar pegawai.',
        ];
    }
}
