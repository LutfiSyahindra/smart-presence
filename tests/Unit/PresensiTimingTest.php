<?php

namespace Tests\Unit;

use App\Models\Presensi;
use App\Models\Shift;
use Tests\TestCase;

class PresensiTimingTest extends TestCase
{
    public function test_late_minutes_accept_shift_times_without_seconds(): void
    {
        $presensi = new Presensi([
            'tanggal_presensi' => '2026-09-16',
            'clock_in_at' => '2026-09-16 07:10:00',
        ]);
        $presensi->setRelation('shift', new Shift([
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]));

        $this->assertSame(10, $presensi->lateMinutes());
    }

    public function test_early_departure_minutes_are_calculated_for_a_day_shift(): void
    {
        $presensi = $this->presensi('07:00', '15:00', '2026-09-16', '2026-09-16 14:30:00');

        $this->assertSame(30, $presensi->earlyDepartureMinutes());
    }

    public function test_early_departure_minutes_are_calculated_for_an_overnight_shift(): void
    {
        $presensi = $this->presensi('22:00', '06:00', '2026-09-15', '2026-09-16 05:30:00');

        $this->assertSame(30, $presensi->earlyDepartureMinutes());
    }

    public function test_clock_out_at_or_after_the_scheduled_end_is_not_early(): void
    {
        $presensi = $this->presensi('07:00', '15:00', '2026-09-16', '2026-09-16 15:05:00');

        $this->assertSame(0, $presensi->earlyDepartureMinutes());
    }

    private function presensi(string $start, string $end, string $date, string $clockOut): Presensi
    {
        $presensi = new Presensi([
            'tanggal_presensi' => $date,
            'clock_out_at' => $clockOut,
        ]);
        $presensi->setRelation('shift', new Shift([
            'jam_masuk' => $start,
            'jam_keluar' => $end,
        ]));

        return $presensi;
    }
}
