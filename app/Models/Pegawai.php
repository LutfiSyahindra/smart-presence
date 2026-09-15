<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pegawai extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'unit_kerja_id',
        'jabatan_id',
        'nik',
        'nama',
        'profesi',
        'jenis_kelamin',
        'status_pegawai',
        'tanggal_mulai_bekerja',
        'tanggal_lahir',
        'nomor_telepon',
        'email',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_mulai_bekerja' => 'date',
            'tanggal_lahir' => 'date',
        ];
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /** @return BelongsTo<Jabatan, $this> */
    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
