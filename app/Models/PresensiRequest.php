<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresensiRequest extends Model
{
    protected $fillable = ['presensi_id', 'user_id', 'nama_pegawai', 'nama_jabatan', 'nama_unit',
        'tanggal_presensi', 'action', 'recorded_at', 'location', 'photo_path', 'alasan', 'status', 'current_level', 'decided_at'];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return ['tanggal_presensi' => 'immutable_date', 'recorded_at' => 'immutable_datetime',
            'location' => 'array', 'current_level' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PresensiApproval::class)->orderBy('level');
    }

    public function canBeViewedBy(User $user): bool
    {
        return $user->hasRole('super-admin') || (int) $this->user_id === (int) $user->id
            || $this->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id);
    }

    public function canBeDecidedBy(User $user): bool
    {
        return $this->status === 'pending' && (int) $this->user_id !== (int) $user->id
            && $this->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id
                && $step->level === $this->current_level && $step->status === 'pending');
    }

    public function scopeAwaitingApprovalBy(Builder $query, User $user): Builder
    {
        return $query->where('status', 'pending')->where('user_id', '!=', $user->id)
            ->whereHas('approvals', fn ($steps) => $steps->where('user_id', $user->id)->where('status', 'pending')
                ->whereColumn('presensi_approvals.level', 'presensi_requests.current_level'));
    }
}
