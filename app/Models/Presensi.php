<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presensi extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'pegawai_id',
        'shift_id',
        'tanggal_presensi',
        'clock_in_at',
        'clock_out_at',
        'clock_in_location_id',
        'clock_out_location_id',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_in_address',
        'clock_out_address',
        'clock_in_photo_path',
        'face_verified_at',
        'face_similarity',
        'face_verification_provider',
        'clock_out_photo_path',
        'is_day_off',
        'overtime_minutes',
        'notes',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_presensi' => 'date',
            'clock_in_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'face_similarity' => 'float',
            'clock_out_at' => 'datetime',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'is_day_off' => 'boolean',
            'overtime_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Pegawai, $this> */
    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<OfficeLocation, $this> */
    public function clockInLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'clock_in_location_id');
    }

    /** @return BelongsTo<OfficeLocation, $this> */
    public function clockOutLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class, 'clock_out_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function lateMinutes(): int
    {
        if ($this->is_day_off || ! $this->clock_in_at || ! $this->shift || ! $this->tanggal_presensi) {
            return 0;
        }

        $scheduledClockIn = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $this->tanggal_presensi->format('Y-m-d').' '.(string) $this->shift->jam_masuk,
        );

        return $this->clock_in_at->gt($scheduledClockIn)
            ? (int) $scheduledClockIn->diffInMinutes($this->clock_in_at)
            : 0;
    }
}
