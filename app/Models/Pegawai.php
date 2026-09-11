<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pegawai extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nama',
        'tanggal_lahir',
        'nomor_telepon',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
        ];
    }
}
