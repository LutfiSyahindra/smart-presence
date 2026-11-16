<?php

namespace App\Http\Requests\Absensi\AdminWeb\MasterData\Lembur;

class ImportLemburBalanceRequest extends LemburBalanceSelectionRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'file.required' => 'Pilih file Excel saldo lembur.', 'file.mimes' => 'Gunakan file Excel .xlsx atau .xls.',
            'file.extensions' => 'Ekstensi file harus .xlsx atau .xls.', 'file.max' => 'Ukuran file maksimal 5 MB.',
            'note.max' => 'Catatan maksimal 1.000 karakter.'];
    }
}
