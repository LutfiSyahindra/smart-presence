<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pegawai extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'unit_kerja_id',
        'jabatan_id',
        'shift_id',
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
            'shift_assigned_at' => 'datetime',
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

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function shiftAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shift_assigned_by');
    }

    /** @return HasMany<Presensi, $this> */
    public function presensis(): HasMany
    {
        return $this->hasMany(Presensi::class);
    }
}
