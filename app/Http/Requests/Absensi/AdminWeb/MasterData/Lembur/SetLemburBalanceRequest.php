<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur;

use App\Services\Absensi\AdminWeb\MasterData\Lembur\LemburBalanceService;
use Illuminate\Foundation\Http\FormRequest;

class SetLemburBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => is_string($this->input('note')) ? trim($this->input('note')) : $this->input('note')]);
    }

    public function rules(): array
    {
        return ['pegawai_id' => ['required', 'integer', 'exists:pegawais,id'],
            'minutes' => ['required', 'integer', 'between:0,'.LemburBalanceService::MAX_MINUTES],
            'note' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['pegawai_id.required' => 'Pilih pegawai.', 'pegawai_id.exists' => 'Pegawai tidak ditemukan.',
            'minutes.required' => 'Isi saldo tersedia dalam menit.', 'minutes.integer' => 'Saldo harus berupa menit bulat.',
            'minutes.between' => 'Saldo harus antara 0 dan '.LemburBalanceService::MAX_MINUTES.' menit.',
            'note.required' => 'Catatan pengaturan saldo wajib diisi.', 'note.max' => 'Catatan maksimal 1.000 karakter.'];
    }
}
