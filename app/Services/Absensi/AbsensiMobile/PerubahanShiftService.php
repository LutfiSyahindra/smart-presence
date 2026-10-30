<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\PerubahanShiftRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Notifications\PerubahanShiftNotificationService;
use App\Services\Absensi\ShiftHolidayService;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PerubahanShiftService
{
    public function __construct(private readonly PerubahanShiftNotificationService $notifications) {}

    public function context(User $user, bool $lock = false): array
    {
        $pegawai = $user->pegawai()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $jabatan = $pegawai?->jabatan_id ? Jabatan::query()->with('unitKerja')
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($pegawai->jabatan_id) : null;
        $line = $jabatan ? ApprovalLine::query()->where('type', ApprovalLine::TYPE_PERUBAHAN_SHIFT)
            ->whereHas('jabatans', fn ($query) => $query->whereKey($jabatan->id))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first() : null;
        if ($line) {
            $line->setRelation('steps', $line->steps()->with('user')->when($lock, fn ($query) => $query->lockForUpdate())->get());
        }
        $reason = match (true) {
            ! $pegawai || ! $jabatan => 'Akun Anda belum terhubung ke pegawai dengan jabatan. Hubungi administrator.',
            ! $jabatan->aktif || ! $jabatan->unitKerja?->aktif => 'Unit atau jabatan Anda tidak aktif. Hubungi administrator.',
            ! $pegawai->shift => 'Shift Anda belum ditentukan. Hubungi administrator.',
            ! $line || $line->steps->isEmpty() => 'Approval line perubahan shift untuk jabatan Anda belum diatur. Hubungi administrator.',
            $line->steps->count() > 3 || $line->steps->pluck('level')->all() !== range(1, $line->steps->count())
                || $line->steps->pluck('user_id')->unique()->count() !== $line->steps->count() => 'Urutan approval line perubahan shift tidak valid. Hubungi administrator.',
            $line->steps->contains('user_id', $user->id) => 'Anda tidak dapat menjadi approver pengajuan sendiri. Hubungi administrator.',
            $line->steps->contains(fn ($step) => ! $step->user || ! $step->user->hasVerifiedEmail()
                || (! $step->user->can(AccessPermission::MOBILE_ACCESS)
                    && ! (PengajuanAccess::allows($step->user) && $step->user->can(AccessPermission::CUTI_VIEW)
                        && $step->user->can(AccessPermission::CUTI_APPROVE)))) => 'Ada approver yang belum terverifikasi atau belum memiliki akses persetujuan web/mobile. Hubungi administrator.',
            default => null,
        };

        return compact('pegawai', 'jabatan', 'line') + ['unavailable_reason' => $reason];
    }

    public static function shiftSnapshot(Shift $shift): array
    {
        return ['id' => $shift->id, 'name' => $shift->nama,
            'start_time' => substr($shift->jam_masuk, 0, 5), 'end_time' => substr($shift->jam_keluar, 0, 5),
            'next_day' => $shift->jam_keluar <= $shift->jam_masuk];
    }

    public function submit(User $user, array $data): PerubahanShiftRequest
    {
        return DB::transaction(function () use ($user, $data) {
            // Lock the employee to serialize duplicate requests for the same date.
            ['pegawai' => $pegawai, 'jabatan' => $jabatan, 'line' => $line, 'unavailable_reason' => $reason] = $this->context($user, lock: true);
            if ($reason) {
                throw ValidationException::withMessages(['pengajuan' => $reason]);
            }
            $shifts = Shift::query()->whereIn('id', [$pegawai->shift_id, $data['requested_shift_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $original = $shifts->get($pegawai->shift_id);
            $target = $shifts->get($data['requested_shift_id']);
            if (! $original || (int) $data['original_shift_id'] !== (int) $pegawai->shift_id) {
                throw ValidationException::withMessages(['original_shift_id' => 'Shift Anda telah berubah. Muat ulang halaman sebelum mengajukan.']);
            }
            if (! $target || $target->is($original)) {
                throw ValidationException::withMessages(['requested_shift_id' => 'Pilih shift tujuan yang tersedia dan berbeda dari shift saat ini.']);
            }
            $date = $data['tanggal_perubahan'];
            $record = $pegawai->presensis()->whereDate('tanggal_presensi', $date)->lockForUpdate()->first();
            $allowClocked = app(ConfigurationService::class)->current()->perubahan_shift_after_clock_in && $record?->clock_in_at;
            if (! $allowClocked && (CarbonImmutable::parse($date.' '.$original->jam_masuk)->lte(now())
                || CarbonImmutable::parse($date.' '.$target->jam_masuk)->lte(now()))) {
                throw ValidationException::withMessages(['tanggal_perubahan' => 'Perubahan hanya dapat diajukan sebelum shift asal dan shift tujuan dimulai.']);
            }
            if ($record && (! $allowClocked || (int) $record->shift_id !== (int) $original->id)) {
                throw ValidationException::withMessages(['tanggal_perubahan' => 'Tanggal ini sudah memiliki catatan presensi. Pilih tanggal lain.']);
            }
            if (CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('status', 'approved')
                ->whereDate('tanggal_mulai', '<=', $date)->whereDate('tanggal_selesai', '>=', $date)->exists()) {
                throw ValidationException::withMessages(['tanggal_perubahan' => 'Tanggal ini termasuk cuti yang sudah disetujui. Pilih tanggal lain.']);
            }
            if (PerubahanShiftRequest::query()->where('pegawai_id', $pegawai->id)->whereDate('tanggal_perubahan', $date)
                ->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['tanggal_perubahan' => 'Sudah ada pengajuan menunggu atau disetujui pada tanggal ini.']);
            }
            $request = PerubahanShiftRequest::query()->create([
                'user_id' => $user->id, 'pegawai_id' => $pegawai->id, 'jabatan_id' => $jabatan->id,
                'original_shift_id' => $original->id, 'requested_shift_id' => $target->id,
                'nama_pegawai' => $pegawai->nama, 'nama_jabatan' => $jabatan->nama, 'nama_unit' => $jabatan->unitKerja->nama,
                'tanggal_perubahan' => $date, 'original_shift' => self::shiftSnapshot($original), 'requested_shift' => self::shiftSnapshot($target),
                'alasan' => $data['alasan'], 'status' => 'pending', 'current_level' => 1,
            ]);
            foreach ($line->steps as $step) {
                $request->approvals()->create(['user_id' => $step->user_id, 'approver_name' => $step->user->name,
                    'level' => $step->level, 'status' => $step->level === 1 ? 'pending' : 'waiting']);
            }
            $this->notifications->send($request, 'submitted', $user);

            return $request;
        });
    }

    public function decide(PerubahanShiftRequest $request, User $actor, array $data): void
    {
        DB::transaction(function () use ($request, $actor, $data) {
            $request = PerubahanShiftRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->canBeDecidedBy($actor), 403);
            $decision = $data['decision'] ?? null;
            $note = trim($data['note'] ?? '');
            if (! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
            }
            if ($decision === 'rejected' && $note === '') {
                throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi.']);
            }
            app(ConfigurationService::class)->assertCanDecide($request->tanggal_perubahan);
            $step = $request->approvals()->where('level', $request->current_level)->lockForUpdate()->firstOrFail();
            if ($decision === 'rejected') {
                $step->update(['status' => 'rejected', 'note' => $note, 'decided_at' => now()]);
                $request->approvals()->where('status', 'waiting')->update(['status' => 'skipped']);
                $request->update(['status' => 'rejected', 'current_level' => null, 'decided_at' => now()]);
                $this->notifications->send($request, 'rejected', $actor);

                return;
            }
            $next = $request->approvals()->where('level', '>', $request->current_level)->orderBy('level')->lockForUpdate()->first();
            if (! $next) {
                $this->applySchedule($request, $actor);
            }
            $step->update(['status' => 'approved', 'note' => $note ?: null, 'decided_at' => now()]);
            if ($next) {
                $next->update(['status' => 'pending']);
                $request->update(['current_level' => $next->level]);
            } else {
                $request->update(['status' => 'approved', 'current_level' => null, 'decided_at' => now()]);
            }
            $this->notifications->send($request, $next ? 'approval_required' : 'approved', $actor);
        });
    }

    private function applySchedule(PerubahanShiftRequest $request, User $actor): void
    {
        $pegawai = Pegawai::query()->lockForUpdate()->find($request->pegawai_id);
        $shift = Shift::query()->lockForUpdate()->find($request->requested_shift_id);
        if (! $pegawai || ! $shift || (int) $pegawai->shift_id !== (int) $request->original_shift_id) {
            throw ValidationException::withMessages(['decision' => 'Pegawai atau shift sudah berubah/tidak tersedia. Pengajuan perlu diperbarui.']);
        }
        $date = $request->tanggal_perubahan->toDateString();
        $record = Presensi::query()->where('pegawai_id', $pegawai->id)->whereDate('tanggal_presensi', $date)->lockForUpdate()->first();
        $configuration = app(ConfigurationService::class)->current();
        $allowClocked = $configuration->perubahan_shift_after_clock_in && $record?->clock_in_at;
        $allowPast = $configuration->approve_past_requests && $date < now()->toDateString();
        if (! $allowClocked && ! $allowPast && (CarbonImmutable::parse($date.' '.$request->original_shift['start_time'])->lte(now())
            || CarbonImmutable::parse($date.' '.$shift->jam_masuk)->lte(now()))) {
            throw ValidationException::withMessages(['decision' => 'Shift sudah dimulai atau tanggal pengajuan telah lewat. Perubahan tidak dapat diterapkan.']);
        }
        if (substr($shift->jam_masuk, 0, 5) !== $request->requested_shift['start_time']
            || substr($shift->jam_keluar, 0, 5) !== $request->requested_shift['end_time']) {
            throw ValidationException::withMessages(['decision' => 'Jam shift tujuan telah berubah sejak pengajuan. Minta pemohon mengajukan ulang.']);
        }
        if (($record && (! $allowClocked || (int) $record->shift_id !== (int) $request->original_shift_id))
            || CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('status', 'approved')
                ->whereDate('tanggal_mulai', '<=', $date)->whereDate('tanggal_selesai', '>=', $date)->exists()
            || LemburRequest::query()->where('pegawai_id', $pegawai->id)->whereDate('tanggal_lembur', $date)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['decision' => 'Tanggal ini sudah memiliki presensi, cuti, atau pengajuan lembur aktif. Selesaikan konflik jadwal terlebih dahulu.']);
        }
        if (app(ShiftHolidayService::class)->reason($shift, $date)) {
            throw ValidationException::withMessages(['decision' => 'Tanggal ini merupakan hari libur pada shift tujuan. Pilih shift atau tanggal lain.']);
        }
        // A dated schedule uses the existing attendance record; the employee's default shift stays unchanged.
        $values = ['shift_id' => $shift->id, 'is_day_off' => false,
            'notes' => trim(($record?->notes ? $record->notes."\n" : '').'Perubahan shift disetujui #'.$request->id), 'updated_by' => $actor->id];
        if ($record) {
            $record->update($values);
        } else {
            Presensi::query()->create(['pegawai_id' => $pegawai->id, 'tanggal_presensi' => $date, ...$values]);
        }
    }

    public function cancel(PerubahanShiftRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor) {
            $request = PerubahanShiftRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless((int) $request->user_id === (int) $actor->id, 403);
            if (! $request->canBeCancelledBy($actor)) {
                throw ValidationException::withMessages(['cancel' => 'Status pengajuan atau konfigurasi tidak mengizinkan pembatalan perubahan shift ini.']);
            }
            if ($request->status === 'approved') {
                Pegawai::query()->lockForUpdate()->find($request->pegawai_id);
                $record = Presensi::query()->where('pegawai_id', $request->pegawai_id)
                    ->whereDate('tanggal_presensi', $request->tanggal_perubahan)->lockForUpdate()->first();
                $original = Shift::query()->find($request->original_shift_id);
                if (! $record || ! $original || (int) $record->shift_id !== (int) $request->requested_shift_id) {
                    throw ValidationException::withMessages(['cancel' => 'Jadwal sudah berubah atau shift asal tidak tersedia. Hubungi administrator.']);
                }
                if (LemburRequest::query()->where('pegawai_id', $request->pegawai_id)->whereDate('tanggal_lembur', $request->tanggal_perubahan)
                    ->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['cancel' => 'Tanggal ini memiliki pengajuan lembur aktif. Selesaikan pengajuan lembur terlebih dahulu.']);
                }
                if (! $record->clock_in_at && ! $record->clock_out_at && $record->notes === 'Perubahan shift disetujui #'.$request->id) {
                    $record->delete();
                } else {
                    $record->update(['shift_id' => $original->id,
                        'is_day_off' => (bool) app(ShiftHolidayService::class)->reason($original, $request->tanggal_perubahan),
                        'notes' => trim($record->notes."\nPembatalan perubahan shift #".$request->id), 'updated_by' => $actor->id]);
                }
            }
            $request->approvals()->whereIn('status', ['pending', 'waiting'])->update(['status' => 'skipped']);
            $request->update(['status' => 'cancelled', 'current_level' => null, 'cancelled_at' => now()]);
            $this->notifications->send($request, 'cancelled', $actor);
        });
    }
}
