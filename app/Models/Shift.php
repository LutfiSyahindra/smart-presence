<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nama',
        'jam_masuk',
        'jam_keluar',
    ];

    /** @return HasMany<Presensi, $this> */
    public function presensis(): HasMany
    {
        return $this->hasMany(Presensi::class);
    }
}
