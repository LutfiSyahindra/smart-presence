<?php

namespace App\Http\Requests\Absensi\AdminWeb\ApprovalLine;

use App\Models\ApprovalLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveApprovalLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware checks the relevant create/update permission.
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('type')) {
            $this->merge(['type' => $this->route('approvalLine')?->type ?? ApprovalLine::TYPE_CUTI]);
        }
    }

    public function rules(): array
    {
        $type = $this->input('type');
        if (! in_array($type, ApprovalLine::types(), true)) {
            $type = $this->route('approvalLine')?->type ?? ApprovalLine::TYPE_CUTI;
        }

        return [
            'type' => ['required', 'string', Rule::in($this->route('approvalLine') ? [$this->route('approvalLine')->type] : ApprovalLine::types())],
            'jabatan_ids' => ['required', 'array', 'list', 'min:1'],
            'jabatan_ids.*' => [
                'required', 'integer', 'distinct', Rule::exists('jabatans', 'id')->where('aktif', true),
                Rule::unique('approval_line_jabatan', 'jabatan_id')->where('type', $type)
                    ->ignore($this->route('approvalLine')?->id, 'approval_line_id'),
            ],
            'approver_ids' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'approver_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Pilih kategori approval line.',
            'type.in' => $this->route('approvalLine')
                ? 'Kategori approval line tidak dapat diubah. Buat alur baru untuk kategori lain.'
                : 'Kategori approval line harus cuti atau lembur.',
            'jabatan_ids.required' => 'Pilih minimal satu jabatan pemohon.',
            'jabatan_ids.min' => 'Pilih minimal satu jabatan pemohon.',
            'jabatan_ids.list' => 'Daftar jabatan pemohon tidak valid.',
            'jabatan_ids.*.required' => 'Pilih jabatan pemohon.',
            'jabatan_ids.*.distinct' => 'Jabatan yang sama hanya boleh dipilih sekali.',
            'jabatan_ids.*.exists' => 'Jabatan harus aktif dan tersedia.',
            'jabatan_ids.*.unique' => 'Approval line untuk jabatan ini sudah tersedia pada kategori yang dipilih. Silakan ubah data tersebut.',
            'approver_ids.required' => 'Minimal satu approver wajib dipilih.',
            'approver_ids.min' => 'Minimal satu approver wajib dipilih.',
            'approver_ids.max' => 'Maksimal tiga tingkat approval.',
            'approver_ids.list' => 'Urutan approver harus berurutan mulai dari tingkat 1.',
            'approver_ids.*.required' => 'Pilih pengguna untuk tingkat ini.',
            'approver_ids.*.distinct' => 'Pengguna yang sama tidak boleh mengisi lebih dari satu tingkat.',
            'approver_ids.*.exists' => 'Pengguna yang dipilih tidak tersedia.',
        ];
    }
}
