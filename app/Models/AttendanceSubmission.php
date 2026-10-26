<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AttendanceSubmission extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'received_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_dispatched_at' => 'datetime',
            'attendance_attributes' => 'array',
            'outside_radius' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    public function setAttendanceDateAttribute($value): void
    {
        $this->attributes['attendance_date'] = Carbon::parse($value)->toDateString();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->where(fn (Builder $query) => $query
            ->whereIn('status', ['pending', 'processing'])
            ->orWhere('received_at', '>=', now()->startOfDay()));
    }

    /** Only public status information; never expose paths, GPS proofs, or model snapshots. */
    public function statusPayload(): array
    {
        $attendance = $this->status === 'succeeded' && $this->presensi_id ? Presensi::query()->find($this->presensi_id) : null;
        $in = $this->action === 'clock-in';
        $label = $in ? 'Clock-in' : 'Clock-out';

        return [
            'id' => $this->id,
            'action' => $this->action,
            'status' => $this->status,
            'received_at' => $this->received_at->toIso8601String(),
            'date' => $this->attendance_date->toDateString(),
            'time' => $this->received_at->format('H:i'),
            'outside_radius' => $this->outside_radius,
            'face_similarity' => $this->status === 'succeeded'
                ? ($in ? $attendance?->face_similarity : $attendance?->clock_out_face_similarity) : null,
            'message' => match ($this->status) {
                'succeeded' => "{$label} berhasil. Waktu presensi telah tercatat.",
                'failed' => $this->error_message,
                'processing' => 'Foto wajah sedang diverifikasi. Anda dapat kembali ke beranda.',
                default => 'Permintaan absensi tersimpan dan menunggu verifikasi wajah.',
            },
            'status_url' => route('absensi.mobile.attendance-submissions.show', $this->id, absolute: false),
            'api_status_url' => route('api.mobile.attendance-submissions.show', $this->id, absolute: false),
            'success_url' => $attendance
                ? route("absensi.mobile.{$this->action}.success", ['submission' => $this->id], absolute: false) : null,
            'retry_url' => route("absensi.mobile.{$this->action}.create", absolute: false),
        ];
    }
}
