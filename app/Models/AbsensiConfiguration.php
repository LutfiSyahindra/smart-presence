<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbsensiConfiguration extends Model
{
    public const DEFAULTS = [
        'cancel_approved_cuti' => false,
        'cancel_approved_lembur' => false,
        'cancel_approved_perubahan_shift' => false,
        'perubahan_shift_after_clock_in' => false,
        'clock_in_tolerance_minutes' => 0,
        'approve_past_requests' => true,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cancel_approved_cuti' => 'boolean',
            'cancel_approved_lembur' => 'boolean',
            'cancel_approved_perubahan_shift' => 'boolean',
            'perubahan_shift_after_clock_in' => 'boolean',
            'clock_in_tolerance_minutes' => 'integer',
            'approve_past_requests' => 'boolean',
        ];
    }
}
