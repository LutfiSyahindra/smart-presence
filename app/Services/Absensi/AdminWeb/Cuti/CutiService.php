<?php

namespace App\Services\Absensi\AdminWeb\Cuti;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CutiService
{
    public function submit(User $user, array $data): CutiRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $pegawai = $user->pegawai()->lockForUpdate()->first();
            if (! $pegawai || ! $pegawai->jabatan_id) {
                throw ValidationException::withMessages(['cuti' => 'Akun Anda harus terhubung ke data pegawai dengan jabatan sebelum mengajukan cuti.']);
            }

            $jabatan = Jabatan::query()->with('unitKerja')->lockForUpdate()->find($pegawai->jabatan_id);
            if (! $jabatan?->aktif || ! $jabatan->unitKerja?->aktif) {
                throw ValidationException::withMessages(['cuti' => 'Unit dan jabatan Anda harus aktif untuk mengajukan cuti.']);
            }

            $line = ApprovalLine::query()->whereHas('jabatans', fn ($query) => $query->whereKey($jabatan->id))->where('type', ApprovalLine::TYPE_CUTI)
                ->lockForUpdate()->first();
            $line?->load('steps.user');

            if (! $line || $line->steps->isEmpty()) {
                throw ValidationException::withMessages(['cuti' => 'Approval line cuti untuk jabatan Anda belum diatur. Hubungi administrator.']);
            }

            if ($line->steps->contains('user_id', $user->id)) {
                throw ValidationException::withMessages(['cuti' => 'Anda tidak dapat menjadi approver untuk pengajuan sendiri. Hubungi administrator untuk memperbaiki approval line.']);
            }

            if ($line->steps->contains(fn ($step) => $step->user === null)) {
                throw ValidationException::withMessages(['cuti' => 'Ada pengguna approver yang tidak tersedia. Hubungi administrator.']);
            }

            if (CutiRequest::query()->where('pegawai_id', $pegawai->id)->whereIn('status', ['pending', 'approved'])
                ->whereDate('tanggal_mulai', '<=', $data['tanggal_selesai'])->whereDate('tanggal_selesai', '>=', $data['tanggal_mulai'])->exists()) {
                throw ValidationException::withMessages(['tanggal_mulai' => 'Tanggal ini bertumpang tindih dengan pengajuan cuti yang menunggu atau sudah disetujui.']);
            }

            $cuti = CutiRequest::query()->create([
                'user_id' => $user->id, 'pegawai_id' => $pegawai->id, 'jabatan_id' => $jabatan->id,
                'nama_pegawai' => $pegawai->nama, 'nama_jabatan' => $jabatan->nama, 'nama_unit' => $jabatan->unitKerja->nama,
                'tanggal_mulai' => $data['tanggal_mulai'], 'tanggal_selesai' => $data['tanggal_selesai'], 'alasan' => $data['alasan'],
                'status' => 'pending', 'current_level' => 1,
            ]);

            foreach ($line->steps as $step) {
                $cuti->approvals()->create([
                    'user_id' => $step->user_id, 'approver_name' => $step->user->name, 'level' => $step->level,
                    'status' => $step->level === 1 ? 'pending' : 'waiting',
                ]);
            }

            return $cuti->load('approvals');
        });
    }

    public function decide(CutiRequest $cuti, User $actor, array $data): CutiRequest
    {
        return DB::transaction(function () use ($cuti, $actor, $data) {
            $cuti = CutiRequest::query()->lockForUpdate()->findOrFail($cuti->id);
            if ($cuti->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Pengajuan ini sudah selesai diproses.']);
            }

            $current = $cuti->approvals()->where('level', $cuti->current_level)->lockForUpdate()->firstOrFail();
            // Identity checks deliberately do not use Gate, including for super-admin.
            abort_unless((int) $current->user_id === (int) $actor->id && (int) $cuti->user_id !== (int) $actor->id, 403);
            if ($current->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Tingkat approval ini sudah diproses.']);
            }

            $current->update(['status' => $data['decision'], 'note' => $data['note'] ?? null, 'decided_at' => now()]);

            if ($data['decision'] === 'rejected') {
                $cuti->approvals()->where('status', 'waiting')->update(['status' => 'skipped']);
                $cuti->update(['status' => 'rejected', 'current_level' => null, 'decided_at' => now()]);
            } else {
                $next = $cuti->approvals()->where('level', '>', $current->level)->first();
                if ($next) {
                    $next->update(['status' => 'pending']);
                    $cuti->update(['current_level' => $next->level]);
                } else {
                    $cuti->update(['status' => 'approved', 'current_level' => null, 'decided_at' => now()]);
                }
            }

            return $cuti->load('approvals');
        });
    }
}
