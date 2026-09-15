<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeLocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
        ];
    }

    /** @return HasMany<Presensi, $this> */
    public function clockInPresensis(): HasMany
    {
        return $this->hasMany(Presensi::class, 'clock_in_location_id');
    }

    /** @return HasMany<Presensi, $this> */
    public function clockOutPresensis(): HasMany
    {
        return $this->hasMany(Presensi::class, 'clock_out_location_id');
    }
}
