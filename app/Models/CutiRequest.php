<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CutiRequest extends Model
{
    protected $fillable = [
        'user_id', 'pegawai_id', 'jabatan_id', 'nama_pegawai', 'nama_jabatan', 'nama_unit',
        'tanggal_mulai', 'tanggal_selesai', 'alasan', 'status', 'current_level', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['tanggal_mulai' => 'date', 'tanggal_selesai' => 'date', 'decided_at' => 'datetime', 'current_level' => 'integer'];
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CutiApproval::class)->orderBy('level');
    }
}
