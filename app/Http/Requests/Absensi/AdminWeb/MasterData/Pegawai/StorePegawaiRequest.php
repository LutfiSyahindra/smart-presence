<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Pegawai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePegawaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150'],
            'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'nomor_telepon' => [
                'required',
                'regex:/^0[0-9]{8,14}$/',
                Rule::unique('pegawais', 'nomor_telepon'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama pegawai wajib diisi.',
            'nama.max' => 'Nama pegawai maksimal 150 karakter.',
            'tanggal_lahir.required' => 'Tanggal lahir wajib diisi.',
            'tanggal_lahir.date_format' => 'Format tanggal lahir tidak valid.',
            'tanggal_lahir.after_or_equal' => 'Tanggal lahir tidak boleh sebelum tahun 1900.',
            'tanggal_lahir.before_or_equal' => 'Tanggal lahir tidak boleh melewati hari ini.',
            'nomor_telepon.required' => 'Nomor telepon wajib diisi.',
            'nomor_telepon.regex' => 'Nomor telepon harus 9-15 digit dan diawali 0.',
            'nomor_telepon.unique' => 'Nomor telepon sudah digunakan pegawai lain.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/[^0-9+]/', '', trim((string) $this->input('nomor_telepon'))) ?? '';
        if (str_starts_with($phone, '+62')) {
            $phone = '0'.substr($phone, 3);
        } elseif (str_starts_with($phone, '62')) {
            $phone = '0'.substr($phone, 2);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '0'.$phone;
        }

        $this->merge([
            'nama' => str($this->input('nama'))->squish()->toString(),
            'tanggal_lahir' => trim((string) $this->input('tanggal_lahir')),
            'nomor_telepon' => $phone,
        ]);
    }
}
