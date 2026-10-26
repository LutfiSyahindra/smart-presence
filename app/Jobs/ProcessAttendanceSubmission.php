<?php

namespace App\Jobs;

use App\Contracts\FaceVerifier;
use App\Models\AttendanceSubmission;
use App\Services\Absensi\AbsensiMobile\QueuedAttendanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAttendanceSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $submissionId)
    {
        $this->onQueue('attendance');
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(QueuedAttendanceService $service, FaceVerifier $verifier): void
    {
        $service->process($this->submissionId, $verifier);
    }

    public function failed(?Throwable $exception): void
    {
        $submission = AttendanceSubmission::query()->find($this->submissionId);
        // A duplicate delivery must not reject another worker's active verification.
        if ($submission && ! $submission->isTerminal()
            && (! $submission->processing_started_at || $submission->processing_started_at->lte(now()->subSeconds(90)))) {
            app(QueuedAttendanceService::class)->reject($submission, 'attendance', 'Verifikasi belum berhasil karena layanan sedang bermasalah. Silakan kirim absensi kembali.');
        }
    }
}
