<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LemburRequest extends Model
{
    protected $fillable = [
        'user_id', 'pegawai_id', 'jabatan_id', 'shift_id', 'nama_pegawai', 'nama_jabatan', 'nama_unit', 'nama_shift',
        'tanggal_lembur', 'shift_start_at', 'shift_end_at', 'before_start_at', 'before_end_at', 'after_start_at', 'after_end_at',
        'before_minutes', 'before_break_minutes', 'after_minutes', 'after_break_minutes', 'duration_minutes',
        'compensation', 'alasan', 'attachments', 'status', 'current_level', 'decided_at',
        'request_kind', 'usage_type', 'usage_start_at', 'usage_end_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lembur' => 'immutable_date', 'shift_start_at' => 'immutable_datetime', 'shift_end_at' => 'immutable_datetime',
            'before_start_at' => 'immutable_datetime', 'before_end_at' => 'immutable_datetime',
            'after_start_at' => 'immutable_datetime', 'after_end_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime',
            'before_minutes' => 'integer', 'before_break_minutes' => 'integer', 'after_minutes' => 'integer',
            'after_break_minutes' => 'integer', 'duration_minutes' => 'integer', 'current_level' => 'integer', 'attachments' => 'array',
            'usage_start_at' => 'immutable_datetime', 'usage_end_at' => 'immutable_datetime',
        ];
    }

    public function setTanggalLemburAttribute($value): void
    {
        $this->attributes['tanggal_lembur'] = $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LemburApproval::class)->orderBy('level');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'lembur_usage_allocations', 'usage_request_id', 'source_request_id')->withPivot('minutes');
    }

    public function scopeAwaitingApprovalBy(Builder $query, User $user): Builder
    {
        return $query->where('status', 'pending')->where('user_id', '!=', $user->id)
            ->whereHas('approvals', fn (Builder $steps) => $steps->where('user_id', $user->id)->where('status', 'pending')
                ->whereColumn('lembur_approvals.level', 'lembur_requests.current_level'));
    }

    public function canBeViewedBy(User $user): bool
    {
        return $user->hasRole('super-admin') || (int) $this->user_id === (int) $user->id
            || $this->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id);
    }

    public function canBeCancelledBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id && ($this->status === 'pending'
            || ($this->status === 'approved' && app(\App\Services\Absensi\ConfigurationService::class)->current()->cancel_approved_lembur));
    }

    public function canBeDecidedBy(User $user): bool
    {
        return $this->status === 'pending' && (int) $this->user_id !== (int) $user->id
            && $this->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id
                && $step->level === $this->current_level && $step->status === 'pending');
    }
}
