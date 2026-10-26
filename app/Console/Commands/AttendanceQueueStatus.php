<?php

namespace App\Console\Commands;

use App\Models\AttendanceSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class AttendanceQueueStatus extends Command
{
    protected $signature = 'attendance:queue-status';

    protected $description = 'Tampilkan jumlah job, hasil permintaan, dan umur antrean absensi';

    public function handle(): int
    {
        $this->table(['Queue', 'Job tersimpan'], array_map(fn ($name) => [$name, Queue::size($name)], ['attendance', 'notifications', 'default']));
        $counts = AttendanceSubmission::query()->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status');
        $this->table(['Status permintaan', 'Jumlah'], array_map(fn ($status) => [$status, (int) ($counts[$status] ?? 0)], ['pending', 'processing', 'succeeded', 'failed']));
        $oldest = AttendanceSubmission::query()->whereIn('status', ['pending', 'processing'])->min('received_at');
        $this->info('Umur permintaan aktif tertua: '.($oldest ? (int) Carbon::parse($oldest)->diffInSeconds(now()).' detik' : 'tidak ada'));
        $this->info('Job gagal: '.DB::table(config('queue.failed.table', 'failed_jobs'))->whereIn('queue', ['attendance', 'notifications', 'default'])->count());

        return self::SUCCESS;
    }
}
