<?php

namespace App\Services\Absensi\AdminWeb\Cuti;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\User;
use App\Services\Notifications\CutiNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CutiService
{
    public function __construct(private readonly CutiNotificationService $notifications) {}

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

            $master = MasterCuti::query()->find($data['master_cuti_id']);
            if (! $master?->aktif) {
                throw ValidationException::withMessages(['master_cuti_id' => 'Jenis cuti tidak aktif atau tidak tersedia.']);
            }
            $start = CarbonImmutable::parse($data['tanggal_mulai']);
            $end = CarbonImmutable::parse($data['tanggal_selesai']);
            $days = (int) $start->diffInDays($end) + 1;
            $annual = $master->jenis === 'tahunan';
            if ($annual && $start->year !== $end->year) {
                throw ValidationException::withMessages(['tanggal_selesai' => 'Cuti tahunan yang melewati pergantian tahun harus diajukan terpisah untuk setiap tahun.']);
            }
            $allocation = PegawaiCuti::query()->where('master_cuti_id', $master->id)->where('pegawai_id', $pegawai->id)
                ->where('tahun', $annual ? $start->year : 0)->lockForUpdate()->first();
            if (! $allocation) {
                throw ValidationException::withMessages(['master_cuti_id' => 'Jenis cuti atau kuota untuk tahun tersebut belum diberikan kepada Anda.']);
            }
            $used = $annual ? (int) CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('master_cuti_id', $master->id)
                ->where('tahun_cuti', $start->year)->whereIn('status', ['pending', 'approved'])->sum('jumlah_hari') : 0;
            $available = $annual ? $allocation->jumlah_hari - $used : $master->durasi_hari;
            if ($days > $available) {
                throw ValidationException::withMessages(['tanggal_selesai' => $annual
                    ? 'Sisa cuti tahunan tidak mencukupi. Tersedia '.max(0, $available).' hari (pengajuan menunggu sudah diperhitungkan).'
                    : "Durasi jenis cuti ini maksimal {$available} hari kalender per pengajuan."]);
            }

            $cuti = CutiRequest::query()->create([
                'master_cuti_id' => $master->id, 'nama_cuti' => $master->nama, 'jumlah_hari' => $days,
                'tahun_cuti' => $annual ? $start->year : null, 'is_tahunan' => $annual,
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

            $this->notifications->send($cuti, 'submitted', $user);

            return $cuti->load('approvals');
        });
    }

    public function cancel(CutiRequest $cuti, User $actor): CutiRequest
    {
        return DB::transaction(function () use ($cuti, $actor) {
            $cuti = CutiRequest::query()->lockForUpdate()->findOrFail($cuti->id);
            abort_unless((int) $cuti->user_id === (int) $actor->id, 403);

            if (! $cuti->canBeCancelledBy($actor)) {
                throw ValidationException::withMessages(['cancel' => 'Status pengajuan atau konfigurasi tidak mengizinkan pembatalan cuti ini.']);
            }

            $cuti->approvals()->whereIn('status', ['pending', 'waiting'])->update(['status' => 'skipped']);
            $cuti->update(['status' => 'cancelled', 'current_level' => null, 'decided_at' => now()]);

            $this->notifications->send($cuti, 'cancelled', $actor);

            return $cuti->load('approvals');
        });
    }

    public function approveMany(User $actor, array $requestIds): int
    {
        return DB::transaction(function () use ($actor, $requestIds) {
            $requests = CutiRequest::query()->whereIn('id', $requestIds)->orderBy('id')->lockForUpdate()->get();
            if ($requests->count() !== count($requestIds)) {
                throw ValidationException::withMessages(['request_ids' => 'Ada pengajuan yang tidak tersedia. Muat ulang daftar dan pilih kembali pengajuan.']);
            }

            foreach ($requests as $cuti) {
                // Reuse the locked identity, current-level, and status checks for every request.
                $this->decide($cuti, $actor, ['decision' => 'approved']);
            }

            return $requests->count();
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

            app(\App\Services\Absensi\ConfigurationService::class)->assertCanDecide($cuti->tanggal_mulai);

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
                    // Serialize final approval with attendance submissions for this employee.
                    Pegawai::query()->lockForUpdate()->find($cuti->pegawai_id);
                    $cuti->update(['status' => 'approved', 'current_level' => null, 'decided_at' => now()]);
                }
            }

            $this->notifications->send($cuti, $cuti->status === 'pending' ? 'approval_required' : $cuti->status, $actor);

            return $cuti->load('approvals');
        });
    }
}
