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

    // Daily SCM sync at midnight (00:00)
    $schedule->command('ams:schedule-sync')
        ->dailyAt('00:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping()
        ->runInBackground();

    // Generate daily reports at 11:59 PM
    $schedule->command('ams:generate-daily-report')
        ->dailyAt('23:59')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping();

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

    // Update delivery compliance status at midnight (00:00)
    $schedule->command('ams:update-delivery-compliance')
        ->dailyAt('23:59')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping();
});
