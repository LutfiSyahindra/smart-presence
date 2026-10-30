<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerubahanShiftRequest extends Model
{
    protected $fillable = [
        'user_id', 'pegawai_id', 'jabatan_id', 'original_shift_id', 'requested_shift_id',
        'nama_pegawai', 'nama_jabatan', 'nama_unit', 'tanggal_perubahan', 'original_shift', 'requested_shift',
        'alasan', 'status', 'current_level', 'cancelled_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['tanggal_perubahan' => 'immutable_date', 'original_shift' => 'array', 'requested_shift' => 'array',
            'current_level' => 'integer', 'cancelled_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PerubahanShiftApproval::class)->orderBy('level');
    }

    public function canBeViewedBy(User $user): bool
    {
        return $user->hasRole('super-admin') || (int) $this->user_id === (int) $user->id
            || $this->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id);
    }

    public function canBeCancelledBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id && ($this->status === 'pending'
            || ($this->status === 'approved' && app(\App\Services\Absensi\ConfigurationService::class)->current()->cancel_approved_perubahan_shift));
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
                ->whereColumn('perubahan_shift_approvals.level', 'perubahan_shift_requests.current_level'));
    }
}
