<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Jabatan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJabatanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->has('jabatans')) {
            return [
                'jabatans' => ['required', 'array', 'min:1', 'max:25'],
                'jabatans.*' => ['required', 'array'],
                'jabatans.*.kode' => [
                    'required',
                    'string',
                    'max:30',
                    'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
                    'distinct:ignore_case',
                    Rule::unique('jabatans', 'kode'),
                ],
                'jabatans.*.nama' => [
                    'required',
                    'string',
                    'max:150',
                    'distinct:ignore_case',
                    Rule::unique('jabatans', 'nama'),
                ],
                'jabatans.*.deskripsi' => ['nullable', 'string', 'max:500'],
                'jabatans.*.aktif' => ['required', 'boolean'],
            ];
        }

        return [
            'kode' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('jabatans', 'kode')],
            'nama' => ['required', 'string', 'max:150', Rule::unique('jabatans', 'nama')],
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
            'jabatans.required' => 'Minimal satu input jabatan harus tersedia.',
            'jabatans.min' => 'Minimal satu input jabatan harus tersedia.',
            'jabatans.max' => 'Maksimal 25 jabatan dapat ditambahkan sekaligus.',
            'jabatans.*.kode.required' => 'Kode jabatan wajib diisi.',
            'jabatans.*.kode.max' => 'Kode jabatan maksimal 30 karakter.',
            'jabatans.*.kode.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung.',
            'jabatans.*.kode.distinct' => 'Kode jabatan tidak boleh sama dalam satu pengiriman.',
            'jabatans.*.kode.unique' => 'Kode jabatan sudah digunakan.',
            'jabatans.*.nama.required' => 'Nama jabatan wajib diisi.',
            'jabatans.*.nama.max' => 'Nama jabatan maksimal 150 karakter.',
            'jabatans.*.nama.distinct' => 'Nama jabatan tidak boleh sama dalam satu pengiriman.',
            'jabatans.*.nama.unique' => 'Nama jabatan sudah digunakan.',
            'jabatans.*.deskripsi.max' => 'Deskripsi maksimal 500 karakter.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('jabatans'))) {
            $jabatans = collect($this->input('jabatans'))
                ->map(fn ($jabatan) => is_array($jabatan) ? [
                    'kode' => str($jabatan['kode'] ?? '')->trim()->upper()->toString(),
                    'nama' => str($jabatan['nama'] ?? '')->squish()->toString(),
                    'deskripsi' => str($jabatan['deskripsi'] ?? '')->squish()->toString() ?: null,
                    'aktif' => filter_var($jabatan['aktif'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ] : $jabatan)
                ->all();

            $this->merge(['jabatans' => $jabatans]);

            return;
        }

        $this->merge([
            'kode' => str($this->input('kode'))->trim()->upper()->toString(),
            'nama' => str($this->input('nama'))->squish()->toString(),
            'deskripsi' => str($this->input('deskripsi'))->squish()->toString() ?: null,
            'aktif' => $this->boolean('aktif'),
        ]);
    }
}
