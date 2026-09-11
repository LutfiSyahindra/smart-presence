<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:100', Rule::unique('shifts', 'nama')->ignore($this->route('shift'))],
            'jam_masuk' => ['required', 'date_format:H:i'],
            'jam_keluar' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama shift sudah digunakan.',
            'jam_masuk.date_format' => 'Jam masuk harus menggunakan format jam yang valid.',
            'jam_keluar.date_format' => 'Jam keluar harus menggunakan format jam yang valid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nama' => str($this->input('nama'))->squish()->toString(),
            'jam_masuk' => trim((string) $this->input('jam_masuk')),
            'jam_keluar' => trim((string) $this->input('jam_keluar')),
        ]);
    }
}
