<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbsensiConfiguration extends Model
{
    public const PRESENSI_PEGAWAI_SCOPE_UNIT = 'unit-kerja';

    public const PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE = 'approval-line';

    public const PRESENSI_PEGAWAI_SCOPE_ALL = 'all';

    public const DEFAULTS = [
        'cancel_approved_cuti' => false,
        'cuti_past_date' => false,
        'cancel_approved_lembur' => false,
        'cancel_approved_perubahan_shift' => false,
        'perubahan_shift_after_clock_in' => false,
        'perubahan_shift_same_day' => false,
        'perubahan_shift_past_date' => false,
        'perubahan_shift_without_default' => false,
        'clock_in_tolerance_minutes' => 0,
        'clock_out_without_clock_in' => false,
        'face_minimum_similarity' => 40,
        'approve_past_requests' => true,
        'allow_self_approval' => false,
        'mobile_presensi_pegawai_scope' => self::PRESENSI_PEGAWAI_SCOPE_UNIT,
        'mobile_presensi_pegawai_approval_type' => ApprovalLine::TYPE_PRESENSI,
        'mobile_presensi_pegawai_all_role_ids' => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cancel_approved_cuti' => 'boolean',
            'cuti_past_date' => 'boolean',
            'cancel_approved_lembur' => 'boolean',
            'cancel_approved_perubahan_shift' => 'boolean',
            'perubahan_shift_after_clock_in' => 'boolean',
            'perubahan_shift_same_day' => 'boolean',
            'perubahan_shift_past_date' => 'boolean',
            'perubahan_shift_without_default' => 'boolean',
            'clock_in_tolerance_minutes' => 'integer',
            'clock_out_without_clock_in' => 'boolean',
            'face_minimum_similarity' => 'float',
            'approve_past_requests' => 'boolean',
            'allow_self_approval' => 'boolean',
            'mobile_presensi_pegawai_all_role_ids' => 'array',
        ];
    }
}
