<?php

namespace App\Http\Requests\Absensi\AdminWeb\Lembur;

use Illuminate\Foundation\Http\FormRequest;

class CancelAdminLemburRequest extends FormRequest
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
        return ['note' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['note.required' => 'Alasan pembatalan wajib diisi.', 'note.max' => 'Alasan pembatalan maksimal 1.000 karakter.'];
    }
}
