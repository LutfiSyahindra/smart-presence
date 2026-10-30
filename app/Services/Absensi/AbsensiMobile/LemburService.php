<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\ShiftHolidayService;
use App\Services\Notifications\LemburNotificationService;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LemburService
{
    public function __construct(private readonly LemburNotificationService $notifications) {}

    /** Approved time-off compensation is credited once; pending usage reserves its allocation. */
    public function balance(User $user): array
    {
        $pegawai = $user->pegawai()->first();
        $earned = $pegawai ? (int) LemburRequest::query()->where('pegawai_id', $pegawai->id)
            ->where('request_kind', 'overtime')->where('compensation', 'time_off')->where('status', 'approved')
            ->whereRaw('COALESCE(after_end_at, before_end_at) <= ?', [now()->toDateTimeString()])->sum('duration_minutes') : 0;
        $usage = $pegawai ? LemburRequest::query()->where('pegawai_id', $pegawai->id)->where('request_kind', 'usage')
            ->whereIn('status', ['pending', 'approved'])->selectRaw('status, SUM(duration_minutes) as minutes')->groupBy('status')->pluck('minutes', 'status') : collect();
        $reserved = (int) ($usage['pending'] ?? 0);
        $used = (int) ($usage['approved'] ?? 0);

        return ['earned_minutes' => $earned, 'reserved_minutes' => $reserved, 'used_minutes' => $used,
            'available_minutes' => max(0, $earned - $reserved - $used)];
    }

    public function submitUsage(User $user, array $data): LemburRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $context = $this->context($user, lock: true);
            if ($context['unavailable_reason']) {
                throw ValidationException::withMessages(['lembur' => $context['unavailable_reason']]);
            }
            ['pegawai' => $pegawai, 'jabatan' => $jabatan, 'line' => $line] = $context;
            $shift = Shift::query()->lockForUpdate()->find($pegawai->shift_id);
            $record = Presensi::query()->where('pegawai_id', $pegawai->id)->whereDate('tanggal_presensi', $data['tanggal_lembur'])->first();
            if (! $shift || (int) $data['shift_id'] !== (int) $shift->id || ($record && (int) $record->shift_id !== (int) $shift->id)) {
                throw ValidationException::withMessages(['shift_id' => 'Shift presensi telah berubah atau tidak sesuai. Muat ulang halaman dan periksa jadwal Anda.']);
            }
            if ($record?->is_day_off || app(ShiftHolidayService::class)->reason($shift, $data['tanggal_lembur'])
                || CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('status', 'approved')
                    ->whereDate('tanggal_mulai', '<=', $data['tanggal_lembur'])->whereDate('tanggal_selesai', '>=', $data['tanggal_lembur'])->exists()) {
                throw ValidationException::withMessages(['tanggal_lembur' => 'Pilih hari kerja yang tidak sedang libur atau cuti disetujui.']);
            }
            $start = CarbonImmutable::parse($data['tanggal_lembur'].' '.substr($shift->jam_masuk, 0, 5));
            $end = CarbonImmutable::parse($data['tanggal_lembur'].' '.substr($shift->jam_keluar, 0, 5));
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }
            $minutes = (int) $data['duration_minutes'];
            $active = LemburRequest::query()->where('pegawai_id', $pegawai->id)->where('request_kind', 'usage')
                ->whereDate('tanggal_lembur', $data['tanggal_lembur'])->whereIn('status', ['pending', 'approved'])->lockForUpdate()->get();
            if ($active->contains('usage_type', $data['usage_type'])) {
                throw ValidationException::withMessages(['usage_type' => 'Sudah ada penggunaan untuk keperluan ini pada tanggal yang sama.']);
            }
            if ($minutes < 1 || $minutes + $active->sum('duration_minutes') >= $start->diffInMinutes($end)) {
                throw ValidationException::withMessages(['duration_minutes' => 'Total penggunaan harus lebih singkat dari durasi shift.']);
            }
            $usageStart = $data['usage_type'] === 'late_arrival' ? $start : $end->subMinutes($minutes);
            $usageEnd = $data['usage_type'] === 'late_arrival' ? $start->addMinutes($minutes) : $end;
            // Employee and source locks serialize concurrent submissions, including different usage dates.
            $sources = LemburRequest::query()->where('pegawai_id', $pegawai->id)->where('request_kind', 'overtime')
                ->where('status', 'approved')->where('compensation', 'time_off')
                ->whereRaw('COALESCE(after_end_at, before_end_at) <= ?', [min(now()->toDateTimeString(), $usageStart->toDateTimeString())])
                ->orderBy('tanggal_lembur')->orderBy('id')->lockForUpdate()->get();
            $allocated = DB::table('lembur_usage_allocations as allocation')->join('lembur_requests as usage', 'usage.id', '=', 'allocation.usage_request_id')
                ->whereIn('allocation.source_request_id', $sources->modelKeys())->whereIn('usage.status', ['pending', 'approved'])
                ->selectRaw('allocation.source_request_id, SUM(allocation.minutes) as minutes')->groupBy('allocation.source_request_id')->pluck('minutes', 'source_request_id');
            $available = $sources->sum(fn ($source) => max(0, $source->duration_minutes - (int) ($allocated[$source->id] ?? 0)));
            if ($minutes > $available) {
                throw ValidationException::withMessages(['duration_minutes' => 'Saldo lembur tersedia tidak mencukupi. Saldo yang menunggu persetujuan sudah dicadangkan.']);
            }
            $usage = LemburRequest::query()->create([
                'request_kind' => 'usage', 'usage_type' => $data['usage_type'], 'usage_start_at' => $usageStart, 'usage_end_at' => $usageEnd,
                'user_id' => $user->id, 'pegawai_id' => $pegawai->id, 'jabatan_id' => $jabatan->id, 'shift_id' => $shift->id,
                'nama_pegawai' => $pegawai->nama, 'nama_jabatan' => $jabatan->nama, 'nama_unit' => $jabatan->unitKerja->nama,
                'nama_shift' => $shift->nama, 'tanggal_lembur' => $data['tanggal_lembur'], 'shift_start_at' => $start, 'shift_end_at' => $end,
                'duration_minutes' => $minutes, 'compensation' => 'time_off', 'alasan' => $data['alasan'],
                'status' => 'pending', 'current_level' => $line->steps->first()->level,
            ]);
            $remaining = $minutes;
            foreach ($sources as $source) {
                $take = min($remaining, max(0, $source->duration_minutes - (int) ($allocated[$source->id] ?? 0)));
                if ($take > 0) {
                    $usage->sources()->attach($source->id, ['minutes' => $take]);
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            foreach ($line->steps as $step) {
                $usage->approvals()->create(['user_id' => $step->user_id, 'approver_name' => $step->user->name,
                    'level' => $step->level, 'status' => $step->level === $usage->current_level ? 'pending' : 'waiting']);
            }
            $this->notifications->send($usage, 'submitted', $user);

            return $usage->load('approvals');
        });
    }

    public function context(User $user, bool $lock = false): array
    {
        $pegawai = $user->pegawai()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $jabatan = $pegawai?->jabatan_id ? Jabatan::query()->with('unitKerja')
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($pegawai->jabatan_id) : null;
        $line = $jabatan ? ApprovalLine::query()->where('type', ApprovalLine::TYPE_LEMBUR)
            ->whereHas('jabatans', fn (Builder $query) => $query->whereKey($jabatan->id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() : null;
        if ($line) {
            $line->setRelation('steps', $line->steps()->with('user')->when($lock, fn ($query) => $query->lockForUpdate())->get());
        }
        $reason = match (true) {
            ! $pegawai || ! $jabatan => 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.',
            ! $jabatan->aktif || ! $jabatan->unitKerja?->aktif => 'Unit atau jabatan Anda tidak aktif. Hubungi administrator.',
            ! $line || $line->steps->isEmpty() => 'Approval line lembur untuk jabatan Anda belum diatur. Hubungi administrator.',
            $line->steps->contains('user_id', $user->id) => 'Anda tidak dapat menyetujui pengajuan sendiri. Hubungi administrator untuk memperbaiki approval line lembur.',
            $line->steps->contains(fn ($step) => ! $step->user || ! $step->user->hasVerifiedEmail()
                || (! $step->user->can(AccessPermission::MOBILE_ACCESS)
                    && ! (PengajuanAccess::allows($step->user) && $step->user->can(AccessPermission::CUTI_VIEW) && $step->user->can(AccessPermission::CUTI_APPROVE)))) => 'Ada approver yang belum memiliki akun terverifikasi atau akses persetujuan web/mobile. Hubungi administrator.',
            default => null,
        };

        return ['pegawai' => $pegawai, 'jabatan' => $jabatan, 'line' => $line, 'unavailable_reason' => $reason];
    }

    public function submit(User $user, array $data): LemburRequest
    {
        $storedPaths = [];
        $createdRequestId = null;
        try {
            return DB::transaction(function () use ($user, $data, &$storedPaths, &$createdRequestId) {
                $context = $this->context($user, lock: true);
                if ($context['unavailable_reason']) {
                    throw ValidationException::withMessages(['lembur' => $context['unavailable_reason']]);
                }
                ['pegawai' => $pegawai, 'jabatan' => $jabatan, 'line' => $line] = $context;
                $shift = Shift::query()->lockForUpdate()->find($data['shift_id']);
                if (! $shift) {
                    throw ValidationException::withMessages(['shift_id' => 'Shift tidak tersedia. Pilih kembali shift.']);
                }
                $before = (int) $data['before_minutes'];
                $after = (int) $data['after_minutes'];
                $beforeBreak = (int) $data['before_break_minutes'];
                $afterBreak = (int) $data['after_break_minutes'];
                if ($before < 0 || $after < 0 || $beforeBreak < 0 || $afterBreak < 0 || $before + $after === 0
                    || ($beforeBreak > 0 && $beforeBreak >= $before) || ($afterBreak > 0 && $afterBreak >= $after)) {
                    throw ValidationException::withMessages(['before_minutes' => 'Durasi lembur dan istirahat tidak valid.']);
                }
                $start = CarbonImmutable::parse($data['tanggal_lembur'].' '.substr($shift->jam_masuk, 0, 5));
                $end = CarbonImmutable::parse($data['tanggal_lembur'].' '.substr($shift->jam_keluar, 0, 5));
                if ($end->lessThanOrEqualTo($start)) {
                    $end = $end->addDay();
                }
                if ($before + $after + $start->diffInMinutes($end) > 1440) {
                    throw ValidationException::withMessages(['after_minutes' => 'Total jadwal shift dan durasi lembur tidak boleh melebihi 24 jam.']);
                }
                $segments = [];
                if ($before > 0) {
                    $segments[] = [$start->subMinutes($before), $start];
                }
                if ($after > 0) {
                    $segments[] = [$end, $end->addMinutes($after)];
                }
                // The employee lock serializes submissions across dates and different shift selections.
                $overlaps = LemburRequest::query()->where('pegawai_id', $pegawai->id)->whereIn('status', ['pending', 'approved'])
                    ->where(function (Builder $query) use ($segments) {
                        foreach ($segments as [$segmentStart, $segmentEnd]) {
                            foreach (['before', 'after'] as $part) {
                                $query->orWhere(fn (Builder $interval) => $interval->where($part.'_start_at', '<', $segmentEnd)
                                    ->where($part.'_end_at', '>', $segmentStart));
                            }
                        }
                    })->exists();
                if ($overlaps) {
                    throw ValidationException::withMessages(['tanggal_lembur' => 'Waktu ini bertumpang tindih dengan pengajuan lembur yang menunggu atau sudah disetujui.']);
                }
                $attachments = [];
                foreach ($data['attachments'] ?? [] as $file) {
                    $path = $file->store('lembur-attachments/'.$user->id, 'local');
                    if (! $path) {
                        throw ValidationException::withMessages(['attachments' => 'Lampiran belum berhasil disimpan. Silakan coba kembali.']);
                    }
                    $storedPaths[] = $path;
                    $attachments[] = ['path' => $path, 'name' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'size' => $file->getSize()];
                }
                $lembur = LemburRequest::query()->create([
                    'user_id' => $user->id, 'pegawai_id' => $pegawai->id, 'jabatan_id' => $jabatan->id, 'shift_id' => $shift->id,
                    'nama_pegawai' => $pegawai->nama, 'nama_jabatan' => $jabatan->nama, 'nama_unit' => $jabatan->unitKerja->nama,
                    'nama_shift' => $shift->nama, 'tanggal_lembur' => $data['tanggal_lembur'], 'shift_start_at' => $start, 'shift_end_at' => $end,
                    'before_start_at' => $before ? $start->subMinutes($before) : null, 'before_end_at' => $before ? $start : null,
                    'after_start_at' => $after ? $end : null, 'after_end_at' => $after ? $end->addMinutes($after) : null,
                    'before_minutes' => $before, 'before_break_minutes' => $beforeBreak, 'after_minutes' => $after,
                    'after_break_minutes' => $afterBreak, 'duration_minutes' => $before + $after - $beforeBreak - $afterBreak,
                    'compensation' => $data['compensation'], 'alasan' => $data['alasan'], 'attachments' => $attachments,
                    'status' => 'pending', 'current_level' => $line->steps->first()->level,
                ]);
                $createdRequestId = $lembur->id;
                foreach ($line->steps as $step) {
                    $lembur->approvals()->create([
                        'user_id' => $step->user_id, 'approver_name' => $step->user->name, 'level' => $step->level,
                        'status' => $step->level === $lembur->current_level ? 'pending' : 'waiting',
                    ]);
                }
                $this->notifications->send($lembur, 'submitted', $user);

                return $lembur->load('approvals');
            });
        } catch (Throwable $exception) {
            // Keep files if a post-commit queue failure occurred after the request was already saved.
            if ($storedPaths !== [] && (! $createdRequestId || ! LemburRequest::query()->whereKey($createdRequestId)->exists())) {
                Storage::disk('local')->delete($storedPaths);
            }
            throw $exception;
        }
    }

    public function decide(LemburRequest $lembur, User $actor, array $data): LemburRequest
    {
        return DB::transaction(function () use ($lembur, $actor, $data) {
            $lembur = LemburRequest::query()->lockForUpdate()->findOrFail($lembur->id);
            // Even a super-admin must be the current assigned approver.
            abort_unless($lembur->canBeDecidedBy($actor), 403);
            app(\App\Services\Absensi\ConfigurationService::class)->assertCanDecide($lembur->tanggal_lembur);
            $current = $lembur->approvals()->where('level', $lembur->current_level)->lockForUpdate()->firstOrFail();
            $current->update(['status' => $data['decision'], 'note' => $data['note'] ?? null, 'decided_at' => now()]);
            if ($data['decision'] === 'rejected') {
                $lembur->approvals()->where('status', 'waiting')->update(['status' => 'skipped']);
                $lembur->update(['status' => 'rejected', 'current_level' => null, 'decided_at' => now()]);
            } else {
                $next = $lembur->approvals()->where('level', '>', $current->level)->first();
                if ($next) {
                    $next->update(['status' => 'pending']);
                    $lembur->update(['current_level' => $next->level]);
                } else {
                    $lembur->update(['status' => 'approved', 'current_level' => null, 'decided_at' => now()]);
                }
            }
            $this->notifications->send($lembur, $lembur->status === 'pending' ? 'approval_required' : $lembur->status, $actor);

            return $lembur->load('approvals');
        });
    }

    public function cancel(LemburRequest $lembur, User $actor): void
    {
        DB::transaction(function () use ($lembur, $actor) {
            // Match usage submission's employee -> source lock order.
            \App\Models\Pegawai::query()->lockForUpdate()->find($lembur->pegawai_id);
            $lembur = LemburRequest::query()->lockForUpdate()->findOrFail($lembur->id);
            abort_unless((int) $lembur->user_id === (int) $actor->id, 403);
            if (! $lembur->canBeCancelledBy($actor)) {
                throw ValidationException::withMessages(['cancel' => 'Status pengajuan atau konfigurasi tidak mengizinkan pembatalan lembur ini.']);
            }
            if ($lembur->status === 'approved' && $lembur->request_kind === 'overtime'
                && DB::table('lembur_usage_allocations as allocation')->join('lembur_requests as usage', 'usage.id', '=', 'allocation.usage_request_id')
                    ->where('allocation.source_request_id', $lembur->id)->whereIn('usage.status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['cancel' => 'Saldo lembur ini masih digunakan oleh pengajuan penggunaan aktif. Batalkan penggunaan tersebut terlebih dahulu.']);
            }
            $lembur->approvals()->whereIn('status', ['pending', 'waiting'])->update(['status' => 'skipped']);
            $lembur->update(['status' => 'cancelled', 'current_level' => null, 'decided_at' => now()]);
            $this->notifications->send($lembur, 'cancelled', $actor);
        });
    }
}
