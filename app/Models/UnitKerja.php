<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitKerja extends Model
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

    /** @return HasMany<Jabatan, $this> */
    public function jabatans(): HasMany
    {
        return $this->hasMany(Jabatan::class)->orderByDesc('aktif')->orderBy('nama');
    }
}
