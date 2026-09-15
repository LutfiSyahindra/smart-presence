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
            'nik' => ['required', 'string', 'max:50', Rule::unique('pegawais', 'nik')],
            'nama' => ['required', 'string', 'max:150'],
            'profesi' => ['nullable', 'string', 'max:150'],
            'unit_kerja_id' => ['required', 'integer', 'exists:unit_kerjas,id'],
            'jabatan_id' => [
                'required',
                'integer',
                Rule::exists('jabatans', 'id')->where(
                    fn ($query) => $query->where('unit_kerja_id', $this->input('unit_kerja_id')),
                ),
            ],
            'jenis_kelamin' => ['required', Rule::in(['Laki-laki', 'Perempuan'])],
            'status_pegawai' => ['required', Rule::in(['Tetap', 'Tidak Tetap'])],
            'tanggal_mulai_bekerja' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'nomor_telepon' => [
                'nullable',
                'regex:/^0[0-9]{8,14}$/',
                Rule::unique('pegawais', 'nomor_telepon'),
            ],
            'email' => ['nullable', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique('pegawais', 'email')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nik.required' => 'NIK pegawai wajib diisi.',
            'nik.unique' => 'NIK sudah digunakan pegawai lain.',
            'nama.required' => 'Nama pegawai wajib diisi.',
            'nama.max' => 'Nama pegawai maksimal 150 karakter.',
            'unit_kerja_id.required' => 'Unit kerja wajib dipilih.',
            'unit_kerja_id.exists' => 'Unit kerja tidak valid.',
            'jabatan_id.required' => 'Jabatan wajib dipilih.',
            'jabatan_id.exists' => 'Jabatan tidak sesuai dengan unit kerja yang dipilih.',
            'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih.',
            'jenis_kelamin.in' => 'Jenis kelamin tidak valid.',
            'status_pegawai.required' => 'Status pegawai wajib dipilih.',
            'status_pegawai.in' => 'Status pegawai tidak valid.',
            'tanggal_mulai_bekerja.required' => 'Tanggal mulai bekerja wajib diisi.',
            'tanggal_mulai_bekerja.date_format' => 'Format tanggal mulai bekerja tidak valid.',
            'tanggal_mulai_bekerja.after_or_equal' => 'Tanggal mulai bekerja tidak boleh sebelum tahun 1900.',
            'tanggal_mulai_bekerja.before_or_equal' => 'Tanggal mulai bekerja tidak boleh melewati hari ini.',
            'tanggal_lahir.date_format' => 'Format tanggal lahir tidak valid.',
            'tanggal_lahir.after_or_equal' => 'Tanggal lahir tidak boleh sebelum tahun 1900.',
            'tanggal_lahir.before_or_equal' => 'Tanggal lahir tidak boleh melewati hari ini.',
            'nomor_telepon.regex' => 'Nomor telepon harus 9-15 digit dan diawali 0.',
            'nomor_telepon.unique' => 'Nomor telepon sudah digunakan pegawai lain.',
            'email.email' => 'Format email tidak valid.',
            'email.max' => 'Email maksimal 255 karakter.',
            'email.unique' => 'Email sudah digunakan pegawai lain.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = preg_replace('/[^0-9+]/', '', trim((string) $this->input('nomor_telepon'))) ?: null;
        if ($phone !== null) {
            if (str_starts_with($phone, '+62')) {
                $phone = '0'.substr($phone, 3);
            } elseif (str_starts_with($phone, '62')) {
                $phone = '0'.substr($phone, 2);
            } elseif (str_starts_with($phone, '8')) {
                $phone = '0'.$phone;
            }
        }

        $this->merge([
            'nik' => str($this->input('nik'))->squish()->toString(),
            'nama' => str($this->input('nama'))->squish()->toString(),
            'profesi' => str($this->input('profesi'))->squish()->toString() ?: null,
            'tanggal_mulai_bekerja' => trim((string) $this->input('tanggal_mulai_bekerja')),
            'tanggal_lahir' => trim((string) $this->input('tanggal_lahir')) ?: null,
            'nomor_telepon' => $phone,
            'email' => str($this->input('email'))->trim()->lower()->toString() ?: null,
        ]);
    }
}
