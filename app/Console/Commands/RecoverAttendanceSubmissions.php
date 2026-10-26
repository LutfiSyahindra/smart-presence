<?php

namespace App\Console\Commands;

use App\Models\AttendanceSubmission;
use App\Services\Absensi\AbsensiMobile\QueuedAttendanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverAttendanceSubmissions extends Command
{
    protected $signature = 'attendance:recover';

    protected $description = 'Kirim ulang permintaan absensi yang belum diproses atau ditinggalkan worker';

    public function handle(QueuedAttendanceService $service): int
    {
        $count = 0;
        AttendanceSubmission::query()->whereIn('status', ['pending', 'processing'])
            ->where('received_at', '<=', now()->subMinutes(2))
            ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinutes(2)))
            ->where(fn ($query) => $query->whereNull('processing_started_at')->orWhere('processing_started_at', '<=', now()->subMinutes(2)))
            ->orderBy('received_at')->limit(300)->get()->each(function (AttendanceSubmission $submission) use ($service, &$count): void {
                $service->dispatch($submission);
                $count++;
            });
        $oldest = AttendanceSubmission::query()->whereIn('status', ['pending', 'processing'])->min('received_at');
        if ($oldest && now()->parse($oldest)->lt(now()->subMinutes(5))) {
            Log::warning('Antrean absensi menunggu lebih dari lima menit.', ['oldest_received_at' => $oldest]);
        }
        $this->info("Pemulihan antrean: {$count} permintaan dikirim ulang.");

        return self::SUCCESS;
    }
}
