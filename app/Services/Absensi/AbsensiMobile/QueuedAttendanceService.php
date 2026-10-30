<?php

namespace App\Services\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Exceptions\FaceVerificationException;
use App\Jobs\ProcessAttendanceSubmission;
use App\Models\AttendanceSubmission;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\User;
use App\Services\Absensi\AttendanceScheduleService;
use App\Services\Absensi\CutiScheduleService;
use App\Services\Absensi\ShiftHolidayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueuedAttendanceService
{
    public function __construct(
        private readonly AttendanceLocationVerifier $locations,
        private readonly AttendanceScheduleService $schedules,
        private readonly CutiScheduleService $cuti,
        private readonly ShiftHolidayService $holidays,
    ) {}

    public function submit(Request $request, string $action): array
    {
        $receivedAt = now();
        $user = $request->user();
        $pegawai = $user?->pegawai()->with('shift')->first();
        if (! $pegawai) {
            $this->fail('attendance', 'Profil pegawai belum terhubung. Hubungi administrator.');
        }
        $requestKey = hash('sha256', "{$user->id}:{$action}:".$request->input('location_token'));
        $paths = [];

        try {
            $submission = DB::transaction(function () use ($request, $action, $receivedAt, $user, $pegawai, $requestKey, &$paths): AttendanceSubmission {
                // All submissions and finalizations for this employee take the same row lock.
                Pegawai::query()->lockForUpdate()->findOrFail($pegawai->id);
                $previous = AttendanceSubmission::query()->where('user_id', $user->id)
                    ->where('request_key', $requestKey)->first();
                $previous ??= AttendanceSubmission::query()->where('pegawai_id', $pegawai->id)->where('action', $action)
                    ->whereIn('status', ['pending', 'processing'])->first();
                if ($previous) {
                    if ((int) $previous->user_id !== (int) $user->id) {
                        $this->fail('attendance', 'Masih ada absensi pegawai ini yang sedang diproses. Hubungi administrator.');
                    }

                    return $previous;
                }

                $attendance = $action === 'clock-in' ? $this->schedules->clockInRecord($pegawai) : $this->clockOutRecord($pegawai);
                $this->cuti->ensureCanAttend($pegawai, $attendance);
                $this->holidays->ensureCanAttend($pegawai, $attendance);
                if ($action === 'clock-in' && $attendance?->clock_in_at) {
                    $this->fail('attendance', 'Clock-in hari ini sudah tercatat dan tidak dapat dikirim ulang.');
                }
                if ($action === 'clock-out' && (! $attendance?->clock_in_at || $attendance->clock_out_at)) {
                    $this->fail('attendance', $attendance?->clock_out_at ? 'Clock-out sudah tercatat dan tidak dapat dikirim ulang.' : 'Lakukan clock-in terlebih dahulu sebelum clock-out.');
                }
                if ($action === 'clock-out' && $receivedAt->lt($attendance->clock_in_at)) {
                    $this->fail('attendance', 'Waktu clock-out tidak boleh lebih awal dari waktu clock-in.');
                }
                $office = OfficeLocation::query()->oldest('id')->first();
                if (! $office) {
                    $this->fail('attendance', 'Lokasi kantor belum dikonfigurasi. Hubungi administrator.');
                }
                // GPS freshness and network checks happen now, never after waiting in a queue.
                $location = $this->locations->verify($request, $action);
                $distance = $this->distance($location->latitude, $location->longitude, $office);
                $outside = $distance > $office->radius_meters;
                $outsideNote = trim((string) $request->input('outside_radius_note', ''));
                if ($outside && ! $request->boolean('outside_radius_confirmed')) {
                    $this->fail('outside_radius_confirmed', 'Anda berada di luar radius kantor. Tinjau lokasi dan berikan catatan sebelum melanjutkan.');
                }
                if ($outside && mb_strlen($outsideNote) < 5) {
                    $this->fail('outside_radius_note', 'Catatan lokasi di luar radius wajib diisi minimal 5 karakter.');
                }
                $approval = $outside ? app(PresensiApprovalService::class)->snapshot($user, $outsideNote) : null;
                $profile = $user->profile_photo_path;
                if (! $profile || ! Str::startsWith($profile, "profile-photos/{$user->id}/") || str_contains($profile, '..') || ! Storage::disk('local')->exists($profile)) {
                    $this->fail('photo', 'Foto profil wajah belum tersedia. Buka menu Akun dan tambahkan foto profil terlebih dahulu.');
                }

                $id = (string) Str::uuid();
                $photo = $request->file('photo')->store("presensi-photos/{$pegawai->id}/{$action}", 'local');
                if (! $photo) {
                    $this->fail('photo', 'Foto belum berhasil disimpan. Silakan coba kembali.');
                }
                $paths[] = $photo;
                // Keep an immutable copy per profile version, shared by this user's submissions.
                $profileBytes = Storage::disk('local')->get($profile);
                $snapshot = "attendance-profile-snapshots/{$user->id}/".hash('sha256', $profileBytes);
                if (! Storage::disk('local')->exists($snapshot) && ! Storage::disk('local')->put($snapshot, $profileBytes)) {
                    $this->fail('photo', 'Foto profil belum berhasil disiapkan. Silakan coba kembali.');
                }
                $prefix = $action === 'clock-in' ? 'clock_in' : 'clock_out';
                $notes = $attendance?->notes;
                if ($outside) {
                    $notes = $notes ? $notes."\n\nCatatan {$action} di luar radius: ".$outsideNote : $outsideNote;
                }

                return AttendanceSubmission::query()->create([
                    'id' => $id, 'pegawai_id' => $pegawai->id, 'user_id' => $user->id,
                    'presensi_id' => $attendance?->exists ? $attendance->id : null,
                    'shift_id' => $attendance?->shift_id ?? $pegawai->shift_id,
                    'attendance_date' => ($attendance?->tanggal_presensi ?? $receivedAt)->toDateString(),
                    'action' => $action, 'request_key' => $requestKey,
                    'active_key' => "{$pegawai->id}:{$action}", 'status' => 'pending',
                    'received_at' => $receivedAt, 'photo_path' => $photo, 'profile_snapshot_path' => $snapshot,
                    'outside_radius' => $outside,
                    'approval_context' => $approval,
                    'attendance_attributes' => [
                        "{$prefix}_location_id" => $office->id,
                        "{$prefix}_latitude" => $location->latitude, "{$prefix}_longitude" => $location->longitude,
                        "{$prefix}_accuracy" => $location->accuracy, "{$prefix}_location_sample_count" => $location->sampleCount,
                        "{$prefix}_location_verified_at" => $receivedAt->toDateTimeString(),
                        "{$prefix}_address" => $outside
                            ? sprintf('Di luar radius %s (%s m dari kantor)', $office->name, number_format($distance, 0, ',', '.'))
                            : ($office->address ?: $office->name),
                        "{$prefix}_photo_path" => $photo, 'notes' => $notes, 'updated_by' => $user->id,
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }

        // A persisted request survives a dispatch outage; the recovery command re-enqueues it.
        if ($submission->wasRecentlyCreated) {
            $this->locations->consumeChallenge($request, $action);
            $this->dispatch($submission);
        }

        return ['submission' => $submission->refresh(), 'attendance' => null, 'outside_radius' => $submission->outside_radius,
            'message' => 'Permintaan absensi tersimpan dan menunggu verifikasi wajah.'];
    }

    public function dispatch(AttendanceSubmission $submission): void
    {
        try {
            ProcessAttendanceSubmission::dispatch($submission->id)->afterCommit();
            $submission->forceFill(['last_dispatched_at' => now()])->save();
        } catch (Throwable $exception) {
            Log::error('Permintaan absensi tersimpan; pengiriman job akan dicoba oleh pemulihan antrean.', ['submission_id' => $submission->id, 'exception' => $exception::class]);
        }
    }

    public function process(string $id, FaceVerifier $verifier): void
    {
        $lock = Cache::lock('attendance-submission:'.$id, 75);
        if (! $lock->get()) {
            return;
        }
        $token = (string) Str::uuid();
        try {
            $submission = AttendanceSubmission::query()->find($id);
            if (! $submission || $submission->isTerminal()) {
                return;
            }
            if ($submission->attempts >= 3) {
                $this->reject($submission, 'attendance', 'Verifikasi belum berhasil karena layanan sedang bermasalah. Silakan kirim absensi kembali.');

                return;
            }
            $submission->forceFill(['status' => 'processing', 'processing_token' => $token,
                'processing_started_at' => now(), 'attempts' => $submission->attempts + 1])->save();
            try {
                $verification = $verifier->compare(Storage::disk('local')->get($submission->profile_snapshot_path), Storage::disk('local')->get($submission->photo_path));
            } catch (FaceVerificationException $exception) {
                if ($exception->reason === FaceVerificationException::SERVICE_UNAVAILABLE) {
                    throw $exception;
                }
                $this->reject($submission, 'photo', match ($exception->reason) {
                    FaceVerificationException::INVALID_PROFILE_PHOTO => 'Foto profil tidak dapat digunakan untuk verifikasi. Perbarui foto profil dengan satu wajah yang terlihat jelas.',
                    FaceVerificationException::MULTIPLE_FACES => 'Terdeteksi lebih dari satu wajah. Pastikan hanya Anda yang terlihat lalu coba kembali.',
                    FaceVerificationException::SPOOF_DETECTED => 'Wajah terindikasi berasal dari layar, cetakan, atau rekaman. Hadapkan wajah langsung ke kamera lalu coba kembali.',
                    default => 'Wajah tidak terdeteksi dengan jelas. Hadapkan wajah ke kamera lalu coba kembali.',
                });

                return;
            }
            if (! $verification->matched) {
                $this->reject($submission, 'photo', 'Wajah tidak cocok dengan foto profil. Pastikan Anda menggunakan akun sendiri lalu coba kembali.');

                return;
            }

            DB::transaction(function () use ($submission, $token, $verification): void {
                $pegawai = Pegawai::query()->lockForUpdate()->findOrFail($submission->pegawai_id);
                $current = AttendanceSubmission::query()->lockForUpdate()->findOrFail($submission->id);
                if ($current->isTerminal() || $current->processing_token !== $token) {
                    return;
                }
                if ((int) $pegawai->user_id !== (int) $current->user_id) {
                    $this->fail('attendance', 'Akun yang terhubung ke pegawai telah berubah. Hubungi administrator.');
                }
                $attendance = Presensi::query()->where('pegawai_id', $pegawai->id)
                    ->where('tanggal_presensi', $current->attendance_date->toDateString())->lockForUpdate()->first();
                $in = $current->action === 'clock-in';
                if (($in && $attendance?->clock_in_at) || (! $in && (! $attendance?->clock_in_at || $attendance->clock_out_at))) {
                    $this->fail('attendance', 'Status presensi telah berubah. Periksa riwayat absensi Anda.');
                }
                if ($attendance && (int) $attendance->shift_id !== (int) $current->shift_id) {
                    $this->fail('attendance', 'Jadwal presensi telah berubah. Periksa jadwal dan kirim absensi kembali.');
                }
                if (! $attendance && (int) $pegawai->shift_id !== (int) $current->shift_id) {
                    $this->fail('attendance', 'Shift Anda telah berubah. Periksa jadwal dan kirim absensi kembali.');
                }
                $attendance ??= new Presensi(['pegawai_id' => $pegawai->id, 'shift_id' => $current->shift_id, 'tanggal_presensi' => $current->attendance_date]);
                $this->cuti->ensureCanAttend($pegawai, $attendance, $current->received_at);
                $this->holidays->ensureCanAttend($pegawai, $attendance);
                if (! $in && $current->received_at->lt($attendance->clock_in_at)) {
                    $this->fail('attendance', 'Waktu clock-out tidak boleh lebih awal dari waktu clock-in.');
                }
                $prefix = $in ? 'clock_in' : 'clock_out';
                $facePrefix = $in ? '' : 'clock_out_';
                $attendance->fill($current->attendance_attributes)->fill([
                    "{$prefix}_at" => $current->received_at,
                    "{$facePrefix}face_verified_at" => now(),
                    "{$facePrefix}face_similarity" => $verification->similarity,
                    "{$facePrefix}face_verification_provider" => $verification->provider,
                ]);
                if ($in) {
                    $attendance->is_day_off = false;
                }
                $attendance->save();
                if ($current->outside_radius) {
                    $approval = $current->approval_context;
                    // Handle submissions accepted before this feature was deployed.
                    $approval ??= app(PresensiApprovalService::class)->snapshot(
                        User::query()->findOrFail($current->user_id),
                        $current->attendance_attributes['notes'] ?? 'Presensi di luar radius',
                    );
                    app(PresensiApprovalService::class)->submit($attendance, $current->action, $approval);
                }
                $current->forceFill(['presensi_id' => $attendance->id, 'status' => 'succeeded', 'active_key' => null,
                    'processing_token' => null, 'completed_at' => now(), 'error_field' => null, 'error_message' => null])->save();
            }, 3);
        } catch (ValidationException $exception) {
            $this->reject($submission, 'attendance', collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            AttendanceSubmission::query()->whereKey($id)->where('processing_token', $token)
                ->update(['status' => 'pending', 'processing_token' => null, 'processing_started_at' => null]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function reject(AttendanceSubmission $submission, string $field, string $message): void
    {
        AttendanceSubmission::query()->whereKey($submission->id)->whereIn('status', ['pending', 'processing'])
            ->where('processing_token', $submission->processing_token)
            ->update(['status' => 'failed', 'active_key' => null, 'processing_token' => null,
                'completed_at' => now(), 'error_field' => $field, 'error_message' => $message, 'updated_at' => now()]);
    }

    private function clockOutRecord(Pegawai $pegawai): ?Presensi
    {
        $today = $pegawai->presensis()->with('shift')->where('tanggal_presensi', now()->toDateString())->first();
        if ($today) {
            return $today;
        }
        $previous = $pegawai->presensis()->with('shift')->where('tanggal_presensi', now()->subDay()->toDateString())
            ->whereNotNull('clock_in_at')->latest('clock_in_at')->first();

        return $previous?->shift && $previous->shift->jam_keluar <= $previous->shift->jam_masuk ? $previous : null;
    }

    private function distance(float $latitude, float $longitude, OfficeLocation $office): float
    {
        $a = sin(deg2rad($office->latitude - $latitude) / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($office->latitude)) * sin(deg2rad($office->longitude - $longitude) / 2) ** 2;
        $a = min(1, max(0, $a));

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
