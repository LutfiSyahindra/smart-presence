<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use App\Models\Jabatan;
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
            'unit_kerja_ids' => ['nullable', 'array', 'max:5000'],
            'unit_kerja_ids.*' => ['bail', 'required', 'integer', 'distinct', 'exists:unit_kerjas,id'],
            'jabatan_ids' => ['nullable', 'array', 'max:5000'],
            'jabatan_ids.*' => ['bail', 'required', 'integer', 'distinct', 'exists:jabatans,id'],
            'pegawai_ids' => ['required_if:selection,custom', 'array', 'max:5000'],
            'pegawai_ids.*' => ['required', 'integer', 'distinct', 'exists:pegawais,id'],
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
            if ($this->input('selection') === 'filtered' && ! $this->input('unit_kerja_ids') && ! $this->input('jabatan_ids')) {
                $validator->errors()->add('selection', 'Pilih unit kerja atau jabatan.');
            }
            if ($validator->errors()->isEmpty() && $this->input('unit_kerja_ids') && $this->input('jabatan_ids')
                && Jabatan::query()->whereIn('id', $this->input('jabatan_ids'))->whereNotIn('unit_kerja_id', $this->input('unit_kerja_ids'))->exists()) {
                $validator->errors()->add('jabatan_ids', 'Jabatan harus berada dalam unit kerja yang dipilih.');
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
            'pegawai_ids.*.exists' => 'Pegawai tidak tersedia.',
            'unit_kerja_ids.*.exists' => 'Unit kerja tidak tersedia.',
            'jabatan_ids.*.exists' => 'Jabatan tidak tersedia.',
            'file.required' => 'Pilih template Excel yang sudah diisi.',
            'file.mimes' => 'File harus berupa Excel .xlsx atau .xls.',
            'file.max' => 'Ukuran file Excel maksimal 5 MB.',
        ];
    }
}
