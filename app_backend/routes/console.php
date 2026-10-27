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
// Reminder SMS run only on the central server, or on a clinic computer whose setting
// "This computer sends SMS reminders" is on (offline clinics). See App\Support\SmsRole.
Schedule::command('sms:process-queue')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => \App\Support\SmsRole::enabled());
Schedule::command('sms:send-due-reminders')->dailyAt('08:00')->withoutOverlapping()
    ->when(fn () => \App\Support\SmsRole::enabled());
