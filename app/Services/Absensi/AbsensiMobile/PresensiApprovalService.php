<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\Presensi;
use App\Models\PresensiRequest;
use App\Models\User;
use App\Services\Notifications\PresensiNotificationService;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PresensiApprovalService
{
    public function __construct(private readonly PresensiNotificationService $notifications) {}

    /** Snapshot at submission time; queue delays and later line edits cannot change the approvers. */
    public function snapshot(User $user, string $note): array
    {
        $pegawai = $user->pegawai()->first();
        $jabatan = $pegawai?->jabatan_id ? Jabatan::query()->with('unitKerja')->lockForUpdate()->find($pegawai->jabatan_id) : null;
        $line = $jabatan ? ApprovalLine::query()->where('type', ApprovalLine::TYPE_PRESENSI)
            ->whereHas('jabatans', fn ($query) => $query->whereKey($jabatan->id))->lockForUpdate()->first() : null;
        $steps = $line?->steps()->with('user')->lockForUpdate()->get() ?? collect();
        $reason = match (true) {
            ! $pegawai || ! $jabatan?->aktif || ! $jabatan->unitKerja?->aktif => 'Unit dan jabatan pegawai harus aktif untuk persetujuan presensi. Hubungi administrator.',
            $steps->isEmpty() => 'Approval line presensi untuk jabatan Anda belum diatur. Hubungi administrator.',
            $steps->count() > 3 || $steps->pluck('level')->all() !== range(1, $steps->count())
                || $steps->pluck('user_id')->unique()->count() !== $steps->count() => 'Urutan approval line presensi tidak valid. Hubungi administrator.',
            $steps->contains('user_id', $user->id) => 'Anda tidak dapat menyetujui presensi sendiri. Hubungi administrator.',
            $steps->contains(fn ($step) => ! $step->user?->hasVerifiedEmail()
                || (! ($step->user->can(AccessPermission::MOBILE_ACCESS) && $step->user->can(AccessPermission::MOBILE_NOTIFICATIONS))
                    && ! (PengajuanAccess::allows($step->user) && $step->user->can(AccessPermission::CUTI_VIEW)
                        && $step->user->can(AccessPermission::CUTI_APPROVE) && $step->user->can(AccessPermission::NOTIFICATIONS_VIEW)))) => 'Ada approver yang belum terverifikasi atau belum memiliki akses persetujuan dan notifikasi web/mobile. Hubungi administrator.',
            default => null,
        };
        if ($reason) {
            throw ValidationException::withMessages(['attendance' => $reason]);
        }

        return ['user_id' => $user->id, 'nama_pegawai' => $pegawai->nama, 'nama_jabatan' => $jabatan->nama,
            'nama_unit' => $jabatan->unitKerja->nama, 'alasan' => trim($note),
            'steps' => $steps->map(fn ($step) => ['user_id' => $step->user_id, 'approver_name' => $step->user->name, 'level' => $step->level])->all()];
    }

    /** Must run inside the same transaction as the verified attendance write. */
    public function submit(Presensi $attendance, string $action, array $snapshot): PresensiRequest
    {
        $prefix = $action === 'clock-in' ? 'clock_in' : 'clock_out';
        $office = $action === 'clock-in' ? $attendance->clockInLocation : $attendance->clockOutLocation;
        $request = PresensiRequest::query()->create([
            ...collect($snapshot)->except('steps')->all(), 'presensi_id' => $attendance->id,
            'tanggal_presensi' => $attendance->tanggal_presensi, 'action' => $action, 'recorded_at' => $attendance->{"{$prefix}_at"},
            'photo_path' => $attendance->{"{$prefix}_photo_path"},
            'location' => ['name' => $office?->name, 'radius_meters' => $office?->radius_meters,
                'latitude' => $attendance->{"{$prefix}_latitude"}, 'longitude' => $attendance->{"{$prefix}_longitude"},
                'accuracy' => $attendance->{"{$prefix}_accuracy"}, 'address' => $attendance->{"{$prefix}_address"}],
            'status' => 'pending', 'current_level' => 1,
        ]);
        foreach ($snapshot['steps'] as $step) {
            $request->approvals()->create([...$step, 'status' => $step['level'] === 1 ? 'pending' : 'waiting']);
        }
        $attendance->forceFill(["{$prefix}_approval_status" => 'pending'])->save();
        $this->notifications->send($request, 'submitted', User::query()->findOrFail($snapshot['user_id']));

        return $request;
    }

    public function decide(PresensiRequest $request, User $actor, array $data): void
    {
        DB::transaction(function () use ($request, $actor, $data) {
            $request = PresensiRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->canBeDecidedBy($actor), 403);
            $decision = $data['decision'] ?? null;
            $note = trim($data['note'] ?? '');
            if (! in_array($decision, ['approved', 'rejected'], true) || mb_strlen($note) > 1000) {
                throw ValidationException::withMessages(['decision' => 'Keputusan atau catatan tidak valid.']);
            }
            if ($decision === 'rejected' && $note === '') {
                throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi.']);
            }
            $prefix = $request->action === 'clock-in' ? 'clock_in' : 'clock_out';
            $attendance = Presensi::query()->lockForUpdate()->findOrFail($request->presensi_id);
            if (! $attendance->{"{$prefix}_at"}?->equalTo($request->recorded_at)
                || ! $attendance->tanggal_presensi->equalTo($request->tanggal_presensi)) {
                throw ValidationException::withMessages(['decision' => 'Catatan presensi telah diubah administrator. Periksa kembali data presensi sebelum melanjutkan.']);
            }
            $step = $request->approvals()->where('level', $request->current_level)->lockForUpdate()->firstOrFail();
            $step->update(['status' => $decision, 'note' => $note ?: null, 'decided_at' => now()]);
            $next = $decision === 'approved' ? $request->approvals()->where('status', 'waiting')->orderBy('level')->lockForUpdate()->first() : null;
            if ($next) {
                $next->update(['status' => 'pending']);
                $request->update(['current_level' => $next->level]);
            } else {
                $request->approvals()->where('status', 'waiting')->update(['status' => 'skipped']);
                $request->update(['status' => $decision, 'current_level' => null, 'decided_at' => now()]);
                $prefix = $request->action === 'clock-in' ? 'clock_in' : 'clock_out';
                Presensi::query()->whereKey($request->presensi_id)->update(["{$prefix}_approval_status" => $decision]);
            }
            $this->notifications->send($request, $next ? 'approval_required' : $decision, $actor);
        });
    }
}
