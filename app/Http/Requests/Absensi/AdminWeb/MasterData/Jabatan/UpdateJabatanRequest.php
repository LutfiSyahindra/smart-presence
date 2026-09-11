<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJabatanRequest extends FormRequest
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
                Rule::unique('jabatans', 'kode')->ignore($this->route('jabatan')),
            ],
            'nama' => ['required', 'string', 'max:150', Rule::unique('jabatans', 'nama')->ignore($this->route('jabatan'))],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'aktif' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung.',
            'kode.unique' => 'Kode jabatan sudah digunakan.',
            'nama.unique' => 'Nama jabatan sudah digunakan.',
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
