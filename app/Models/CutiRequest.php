<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CutiRequest extends Model
{
    protected $fillable = [
        'user_id', 'pegawai_id', 'jabatan_id', 'nama_pegawai', 'nama_jabatan', 'nama_unit',
        'tanggal_mulai', 'tanggal_selesai', 'alasan', 'status', 'current_level', 'decided_at',
        'master_cuti_id', 'nama_cuti', 'jumlah_hari', 'tahun_cuti', 'is_tahunan',
    ];

    protected function casts(): array
    {
        return ['tanggal_mulai' => 'date', 'tanggal_selesai' => 'date', 'decided_at' => 'datetime', 'current_level' => 'integer',
            'jumlah_hari' => 'integer', 'tahun_cuti' => 'integer', 'is_tahunan' => 'boolean'];
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CutiApproval::class)->orderBy('level');
    }

    public function scopeAwaitingApprovalBy(Builder $query, User $user): Builder
    {
        return $query->where('status', 'pending')->where('user_id', '!=', $user->id)
            ->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'pending')
                ->whereColumn('cuti_approvals.level', 'cuti_requests.current_level'));
    }

    public function canBeCancelledBy(User $user): bool
    {
        return $this->status === 'pending' && (int) $this->user_id === (int) $user->id;
    }
}
