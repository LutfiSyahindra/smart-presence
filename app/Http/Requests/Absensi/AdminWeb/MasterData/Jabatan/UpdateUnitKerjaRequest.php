<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kode' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                Rule::unique('unit_kerjas', 'kode')->ignore($this->route('unitKerja')),
            ],
            'nama' => [
                'required',
                'string',
                'max:150',
                Rule::unique('unit_kerjas', 'nama')->ignore($this->route('unitKerja')),
            ],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'aktif' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kode.regex' => 'Kode unit hanya boleh berisi huruf, angka, dan tanda hubung.',
            'kode.unique' => 'Kode unit kerja sudah digunakan.',
            'nama.unique' => 'Nama unit kerja sudah digunakan.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'kode' => str($this->input('kode'))->trim()->upper()->toString(),
            'nama' => str($this->input('nama'))->squish()->toString(),
            'deskripsi' => str($this->input('deskripsi'))->squish()->toString() ?: null,
            'aktif' => $this->boolean('aktif'),
        ]);
    }
}
