<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterCuti extends Model
{
    protected $fillable = ['kode', 'nama', 'jenis', 'durasi_hari', 'tanpa_kuota', 'deskripsi', 'aktif'];

    protected function casts(): array
    {
        return ['durasi_hari' => 'integer', 'tanpa_kuota' => 'boolean', 'aktif' => 'boolean'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PegawaiCuti::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(CutiRequest::class);
    }
}
