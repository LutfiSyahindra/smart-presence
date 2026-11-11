<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'selection' => ['required', Rule::in(['all', 'filtered', 'custom'])],
            'unit_kerja_id' => ['nullable', 'integer', 'exists:unit_kerjas,id'],
            'jabatan_id' => ['nullable', 'integer', Rule::exists('jabatans', 'id')->when(
                $this->filled('unit_kerja_id'),
                fn ($rule) => $rule->where('unit_kerja_id', $this->input('unit_kerja_id')),
            )],
            'pegawai_ids' => ['required_if:selection,custom', 'array', 'max:5000'],
            'pegawai_ids.*' => ['required', 'integer', 'distinct', Rule::exists('pegawais', 'id')->whereNotNull('user_id')],
            ...($this->routeIs('*.shift.assign.import') ? [
                'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
            ] : []),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('month')) {
                foreach (['date_from', 'date_to'] as $field) {
                    if (! $validator->errors()->has($field) && substr((string) $this->input($field), 0, 7) !== $this->input('month')) {
                        $validator->errors()->add($field, 'Tanggal harus berada dalam bulan yang dipilih.');
                    }
                }
            }
            if ($this->input('selection') === 'filtered' && ! $this->filled('unit_kerja_id') && ! $this->filled('jabatan_id')) {
                $validator->errors()->add('selection', 'Pilih unit kerja atau jabatan.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'month.required' => 'Pilih bulan jadwal shift.',
            'month.date_format' => 'Bulan jadwal tidak valid.',
            'date_from.required' => 'Pilih tanggal awal.',
            'date_to.required' => 'Pilih tanggal akhir.',
            'date_to.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
            'pegawai_ids.required_if' => 'Pilih minimal satu pegawai.',
            'pegawai_ids.*.exists' => 'Pegawai harus tersedia dan memiliki akun pengguna.',
            'file.required' => 'Pilih template Excel yang sudah diisi.',
            'file.mimes' => 'File harus berupa Excel .xlsx atau .xls.',
            'file.max' => 'Ukuran file Excel maksimal 5 MB.',
        ];
    }
}
