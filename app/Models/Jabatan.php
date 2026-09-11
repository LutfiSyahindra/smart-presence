<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jabatan extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'kode',
        'nama',
        'deskripsi',
        'aktif',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }
}
