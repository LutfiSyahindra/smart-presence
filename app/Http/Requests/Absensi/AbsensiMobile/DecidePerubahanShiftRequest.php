<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidePerubahanShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Current assigned approver is checked under a lock by the service.
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => is_string($this->note) ? trim($this->note) : $this->note]);
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['note.required_if' => 'Alasan penolakan wajib diisi.', 'note.max' => 'Catatan maksimal 1.000 karakter.'];
    }
}
