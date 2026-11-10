<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nama',
        'is_day_off',
        'jam_masuk',
        'jam_keluar',
        'libur_sabtu',
        'libur_minggu',
        'libur_nasional',
        'libur_cuti_bersama',
    ];

    protected function casts(): array
    {
        return [
            'is_day_off' => 'boolean',
            'libur_sabtu' => 'boolean',
            'libur_minggu' => 'boolean',
            'libur_nasional' => 'boolean',
            'libur_cuti_bersama' => 'boolean',
        ];
    }

    /** @return HasMany<Presensi, $this> */
    public function presensis(): HasMany
    {
        return $this->hasMany(Presensi::class);
    }

    /** @return HasMany<Pegawai, $this> */
    public function pegawais(): HasMany
    {
        return $this->hasMany(Pegawai::class);
    }
}
