<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Shift;

use App\Models\HariLibur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveHariLiburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d', Rule::unique('hari_liburs', 'tanggal')
                ->where('jenis', $this->input('jenis'))->ignore($this->route('hariLibur'))],
            'nama' => ['required', 'string', 'max:150'],
            'jenis' => ['required', Rule::in([HariLibur::NASIONAL, HariLibur::CUTI_BERSAMA])],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal.required' => 'Tanggal libur wajib diisi.',
            'tanggal.date_format' => 'Tanggal libur harus menggunakan format tanggal yang valid.',
            'tanggal.unique' => 'Jenis libur ini sudah terdaftar pada tanggal tersebut.',
            'nama.required' => 'Nama hari libur wajib diisi.',
            'nama.max' => 'Nama hari libur maksimal 150 karakter.',
            'jenis.required' => 'Jenis libur wajib dipilih.',
            'jenis.in' => 'Pilih libur nasional atau cuti bersama.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nama' => str($this->input('nama'))->squish()->toString()]);
    }
}
