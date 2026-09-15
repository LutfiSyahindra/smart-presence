<?php

namespace App\Http\Requests\Absensi\AdminWeb\Presensi;

use App\Models\Presensi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePresensiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pegawai_id' => $this->integer('pegawai_id') ?: null,
            'shift_id' => $this->integer('shift_id') ?: null,
            'is_day_off' => $this->boolean('is_day_off'),
            'overtime_minutes' => $this->integer('overtime_minutes'),
            'clock_in_time' => $this->filled('clock_in_time') ? $this->input('clock_in_time') : null,
            'clock_out_time' => $this->filled('clock_out_time') ? $this->input('clock_out_time') : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Presensi|null $presensi */
        $presensi = $this->route('presensi');

        return [
            'pegawai_id' => [
                'required',
                'integer',
                Rule::exists('pegawais', 'id')->where(fn ($query) => $query->whereNotNull('user_id')),
            ],
            'tanggal_presensi' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('presensis', 'tanggal_presensi')
                    ->where(fn ($query) => $query->where('pegawai_id', $this->integer('pegawai_id')))
                    ->ignore($presensi?->id),
            ],
            'shift_id' => [Rule::requiredIf(fn () => ! $this->boolean('is_day_off')), 'nullable', 'integer', 'exists:shifts,id'],
            'clock_in_time' => ['nullable', 'date_format:H:i'],
            'clock_out_time' => ['nullable', 'date_format:H:i'],
            'is_day_off' => ['required', 'boolean'],
            'overtime_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pegawai_id.required' => 'Pegawai wajib dipilih.',
            'pegawai_id.exists' => 'Pegawai harus sudah memiliki akun pengguna.',
            'tanggal_presensi.required' => 'Tanggal presensi wajib diisi.',
            'tanggal_presensi.unique' => 'Presensi pegawai pada tanggal ini sudah tersedia.',
            'shift_id.required' => 'Shift wajib dipilih untuk hari kerja.',
            'clock_in_time.date_format' => 'Jam clock-in tidak valid.',
            'clock_out_time.date_format' => 'Jam clock-out tidak valid.',
            'overtime_minutes.max' => 'Lembur maksimal 24 jam.',
        ];
    }
}
