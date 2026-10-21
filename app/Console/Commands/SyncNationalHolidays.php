<?php

namespace App\Console\Commands;

use App\Exceptions\HolidayCalendarException;
use App\Services\Absensi\NationalHolidaySyncService;
use Illuminate\Console\Command;

class SyncNationalHolidays extends Command
{
    protected $signature = 'holidays:sync {year? : Tahun kalender, default tahun berjalan} {--next-year : Sinkronkan juga tahun berikutnya}';

    protected $description = 'Sinkronkan libur nasional dan cuti bersama Indonesia ke kalender lokal';

    public function handle(NationalHolidaySyncService $service): int
    {
        $year = filter_var($this->argument('year') ?? now()->year, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1900, 'max_range' => 9999],
        ]);
        if ($year === false || ($this->option('next-year') && $year === 9999)) {
            $this->error('Tahun kalender tidak valid.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($this->option('next-year') ? [$year, $year + 1] : [$year] as $calendarYear) {
            try {
                $result = $service->sync($calendarYear);
                $this->info("Kalender {$calendarYear}: {$result['created']} ditambahkan, {$result['updated']} diperbarui, {$result['deleted']} dihapus, {$result['preserved']} entri manual dipertahankan.");
            } catch (HolidayCalendarException $exception) {
                $failed = true;
                $this->error($exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
