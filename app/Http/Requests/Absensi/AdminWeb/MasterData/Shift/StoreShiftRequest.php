<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->has('shifts')) {
            return [
                'shifts' => ['required', 'array', 'min:1', 'max:25'],
                'shifts.*' => ['required', 'array'],
                'shifts.*.nama' => ['required', 'string', 'max:100', 'distinct:ignore_case', Rule::unique('shifts', 'nama')],
                'shifts.*.jam_masuk' => ['required', 'date_format:H:i'],
                'shifts.*.jam_keluar' => ['required', 'date_format:H:i'],
                'shifts.*.libur_sabtu' => ['sometimes', 'boolean'],
                'shifts.*.libur_minggu' => ['sometimes', 'boolean'],
                'shifts.*.libur_nasional' => ['sometimes', 'boolean'],
                'shifts.*.libur_cuti_bersama' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'nama' => ['required', 'string', 'max:100', Rule::unique('shifts', 'nama')],
            'jam_masuk' => ['required', 'date_format:H:i'],
            'jam_keluar' => ['required', 'date_format:H:i'],
            'libur_sabtu' => ['sometimes', 'boolean'],
            'libur_minggu' => ['sometimes', 'boolean'],
            'libur_nasional' => ['sometimes', 'boolean'],
            'libur_cuti_bersama' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama shift sudah digunakan.',
            'jam_masuk.date_format' => 'Jam masuk harus menggunakan format jam yang valid.',
            'jam_keluar.date_format' => 'Jam keluar harus menggunakan format jam yang valid.',
            'shifts.required' => 'Minimal satu input shift harus tersedia.',
            'shifts.min' => 'Minimal satu input shift harus tersedia.',
            'shifts.max' => 'Maksimal 25 shift dapat ditambahkan sekaligus.',
            'shifts.*.nama.required' => 'Nama shift wajib diisi.',
            'shifts.*.nama.max' => 'Nama shift maksimal 100 karakter.',
            'shifts.*.nama.distinct' => 'Nama shift tidak boleh sama dalam satu pengiriman.',
            'shifts.*.nama.unique' => 'Nama shift sudah digunakan.',
            'shifts.*.jam_masuk.required' => 'Jam masuk wajib diisi.',
            'shifts.*.jam_masuk.date_format' => 'Jam masuk harus menggunakan format jam yang valid.',
            'shifts.*.jam_keluar.required' => 'Jam keluar wajib diisi.',
            'shifts.*.jam_keluar.date_format' => 'Jam keluar harus menggunakan format jam yang valid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('shifts'))) {
            $shifts = collect($this->input('shifts'))
                ->map(fn ($shift) => is_array($shift) ? [
                    'nama' => str($shift['nama'] ?? '')->squish()->toString(),
                    'jam_masuk' => trim((string) ($shift['jam_masuk'] ?? '')),
                    'jam_keluar' => trim((string) ($shift['jam_keluar'] ?? '')),
                    'libur_sabtu' => $shift['libur_sabtu'] ?? false,
                    'libur_minggu' => $shift['libur_minggu'] ?? false,
                    'libur_nasional' => $shift['libur_nasional'] ?? false,
                    'libur_cuti_bersama' => $shift['libur_cuti_bersama'] ?? false,
                ] : $shift)
                ->all();

            $this->merge(['shifts' => $shifts]);

            return;
        }

        $this->merge([
            'nama' => str($this->input('nama'))->squish()->toString(),
            'jam_masuk' => trim((string) $this->input('jam_masuk')),
            'jam_keluar' => trim((string) $this->input('jam_keluar')),
        ]);
    }
}
