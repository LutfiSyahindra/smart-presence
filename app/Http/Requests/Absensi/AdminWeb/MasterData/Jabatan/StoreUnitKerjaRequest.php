<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('unit_kerjas', 'kode')],
            'nama' => ['required', 'string', 'max:150', Rule::unique('unit_kerjas', 'nama')],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'aktif' => ['required', 'boolean'],
            'jabatans' => ['required', 'array', 'min:1', 'max:25'],
            'jabatans.*' => ['required', 'array'],
            'jabatans.*.kode' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                'distinct:ignore_case',
            ],
            'jabatans.*.nama' => ['required', 'string', 'max:150', 'distinct:ignore_case'],
            'jabatans.*.deskripsi' => ['nullable', 'string', 'max:500'],
            'jabatans.*.aktif' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kode.regex' => 'Kode unit hanya boleh berisi huruf, angka, dan tanda hubung.',
            'kode.unique' => 'Kode unit kerja sudah digunakan.',
            'nama.unique' => 'Nama unit kerja sudah digunakan.',
            'jabatans.required' => 'Minimal satu jabatan harus diisi untuk unit kerja ini.',
            'jabatans.min' => 'Minimal satu jabatan harus diisi untuk unit kerja ini.',
            'jabatans.max' => 'Maksimal 25 jabatan dapat ditambahkan sekaligus.',
            'jabatans.*.kode.required' => 'Kode jabatan wajib diisi.',
            'jabatans.*.kode.regex' => 'Kode jabatan hanya boleh berisi huruf, angka, dan tanda hubung.',
            'jabatans.*.kode.distinct' => 'Kode jabatan tidak boleh sama dalam unit ini.',
            'jabatans.*.nama.required' => 'Nama jabatan wajib diisi.',
            'jabatans.*.nama.distinct' => 'Nama jabatan tidak boleh sama dalam unit ini.',
            'jabatans.*.deskripsi.max' => 'Deskripsi jabatan maksimal 500 karakter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $jabatans = collect(is_array($this->input('jabatans')) ? $this->input('jabatans') : [])
            ->map(fn ($jabatan) => is_array($jabatan) ? [
                'kode' => str($jabatan['kode'] ?? '')->trim()->upper()->toString(),
                'nama' => str($jabatan['nama'] ?? '')->squish()->toString(),
                'deskripsi' => str($jabatan['deskripsi'] ?? '')->squish()->toString() ?: null,
                'aktif' => filter_var($jabatan['aktif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ] : $jabatan)
            ->all();

        $this->merge([
            'kode' => str($this->input('kode'))->trim()->upper()->toString(),
            'nama' => str($this->input('nama'))->squish()->toString(),
            'deskripsi' => str($this->input('deskripsi'))->squish()->toString() ?: null,
            'aktif' => $this->boolean('aktif'),
            'jabatans' => $jabatans,
        ]);
    }
}
