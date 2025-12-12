<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| AMS SCHEDULE CONFIG - Laravel 12
|--------------------------------------------------------------------------
*/

app()->booted(function () {
    $schedule = app(Schedule::class);

    // Daily SCM sync at midnight (23:55)
    $schedule->command('ams:schedule-sync')
        ->dailyAt('23:55')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->runInBackground();

    // Generate daily reports at 00:05 AM (skip Sunday and Monday to avoid weekend reports)
    $schedule->command('ams:generate-daily-report')
        ->dailyAt('00:05')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->skip(function () {
            // Skip on Sunday (0) and Monday (1) - to avoid generating reports for Saturday and Sunday
            return in_array(now('Asia/Jakarta')->dayOfWeek, [0, 1]);
        });

    // Clean up old sync logs (keep 30 days)
    $schedule->command('ams:cleanup-logs')
        ->weekly()
        ->timezone('Asia/Jakarta');

    // Sync visitor check-in every hour
    $schedule->command('ams:sync-visitor-checkin')
        ->hourly()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->runInBackground();

    // Sync visitor checkout every hour
    $schedule->command('ams:sync-visitor-checkout')
        ->hourly()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->runInBackground();

    // Update arrival status every hour
    $schedule->command('ams:update-arrival-status')
        ->hourly()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->runInBackground();

    // Update delivery compliance status at midnight (23:55)
    $schedule->command('ams:update-delivery-compliance')
        ->dailyAt('23:55')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping();

    // Calculate delivery performance at the beginning of the month (00:05)
    $schedule->command('ams:calculate-delivery-performance')
        ->monthlyOn(1, '00:05')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping();
});
