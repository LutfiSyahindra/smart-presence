<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('holidays:sync --next-year')
    ->dailyAt('02:15')->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('holidays.auto_sync'));

// Keep recovery running even if queue intake is temporarily disabled for a rollback.
Schedule::command('attendance:recover')->everyMinute()->withoutOverlapping();
