<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMasterCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'kode' => is_string($this->kode) ? strtoupper(trim($this->kode)) : $this->kode,
            'nama' => is_string($this->nama) ? trim($this->nama) : $this->nama,
            'jenis' => $this->jenis ?: null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('masterCuti')?->id;

        return [
            'kode' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('master_cutis', 'kode')->ignore($id)],
            'nama' => ['required', 'string', 'max:150'],
            'jenis' => ['nullable', Rule::in(['tahunan']), Rule::unique('master_cutis', 'jenis')->ignore($id)],
            'durasi_hari' => ['required', 'integer', 'between:1,366'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'aktif' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kode.required' => 'Kode cuti wajib diisi.',
            'kode.regex' => 'Kode cuti hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah.',
            'nama.required' => 'Nama jenis cuti wajib diisi.',
            'jenis.unique' => 'Master cuti tahunan sudah tersedia. Gunakan master tersebut untuk mengatur saldo tahunan.',
            'kode.unique' => 'Kode cuti sudah digunakan.',
            'durasi_hari.required' => 'Durasi cuti wajib diisi.',
            'durasi_hari.integer' => 'Durasi cuti harus berupa bilangan bulat.',
            'durasi_hari.between' => 'Durasi harus antara 1 sampai 366 hari.',
        ];
    }
}
