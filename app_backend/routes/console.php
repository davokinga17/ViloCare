<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic Background Sync (runs every minute without overlapping)
// (skipped entirely on the central server, VILOCARE_ROLE=central)
Schedule::command('vilocare:sync')->everyMinute()->withoutOverlapping()
    ->when(fn () => config('sync.role', 'clinic') !== 'central');
Schedule::command('sms:process-queue')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sms:send-due-reminders')->dailyAt('08:00')->withoutOverlapping();
