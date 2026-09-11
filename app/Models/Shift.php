<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nama',
        'jam_masuk',
        'jam_keluar',
    ];
}
