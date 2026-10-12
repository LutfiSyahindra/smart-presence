<?php

namespace App\Http\Requests\Absensi\AdminWeb\Cuti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The service checks the current approver inside a locked transaction.
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => is_string($this->input('note')) ? trim($this->input('note')) : $this->input('note')]);
    }

    public function messages(): array
    {
        return ['note.required_if' => 'Alasan penolakan wajib diisi.', 'note.max' => 'Catatan maksimal 1.000 karakter.'];
    }
}
