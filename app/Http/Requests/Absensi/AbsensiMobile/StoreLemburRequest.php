<?php

namespace App\Http\Requests\Absensi\AbsensiMobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLemburRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tanggal_lembur' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'before_break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'after_break_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'compensation' => ['required', Rule::in(['pay', 'time_off'])],
            'alasan' => ['required', 'string', 'max:200'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'max:10240',
                'mimes:pdf,jpg,jpeg,png,xlsx,xls,docx,doc,csv,txt,ppt,pptx',
                'extensions:pdf,jpg,jpeg,png,xlsx,xls,docx,doc,csv,txt,ppt,pptx'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'alasan' => is_string($this->input('alasan')) ? trim($this->input('alasan')) : $this->input('alasan'),
            ...collect(['before_minutes', 'before_break_minutes', 'after_minutes', 'after_break_minutes'])
                ->mapWithKeys(fn ($key) => [$key => $this->input($key) ?? 0])->all(),
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (['before', 'after'] as $part) {
                $minutes = (int) $this->input($part.'_minutes');
                $break = (int) $this->input($part.'_break_minutes');
                if ($break > 0 && $break >= $minutes) {
                    $validator->errors()->add($part.'_break_minutes', 'Istirahat harus lebih singkat dari durasi lembur pada bagian ini.');
                }
            }
            if ((int) $this->input('before_minutes') + (int) $this->input('after_minutes') === 0) {
                $validator->errors()->add('before_minutes', 'Isi durasi lembur sebelum atau setelah shift.');
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'tanggal_lembur' => 'tanggal lembur', 'shift_id' => 'shift', 'before_minutes' => 'durasi sebelum shift',
            'before_break_minutes' => 'istirahat sebelum shift', 'after_minutes' => 'durasi setelah shift',
            'after_break_minutes' => 'istirahat setelah shift', 'compensation' => 'kompensasi', 'alasan' => 'alasan',
            'attachments' => 'lampiran', 'attachments.*' => 'file lampiran',
        ];
    }

    public function messages(): array
    {
        return [
            '*.required' => ':attribute wajib diisi.', '*.integer' => ':attribute harus berupa menit bulat.',
            '*.min' => ':attribute tidak boleh negatif.', 'shift_id.exists' => 'Shift tidak tersedia. Pilih kembali shift.',
            'alasan.max' => 'Alasan maksimal 200 karakter.', 'attachments.max' => 'Maksimal 5 file lampiran.',
            'attachments.*.max' => 'Ukuran setiap lampiran maksimal 10 MB.',
            'attachments.*.mimes' => 'Format lampiran tidak didukung. Gunakan PDF, gambar, atau dokumen yang tercantum.',
            'attachments.*.extensions' => 'Ekstensi lampiran tidak didukung.',
        ];
    }
}
