<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
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

        // Sync visitor check-in every hour to keep security data up to date
        $schedule->command('ams:sync-visitor-checkin')
            ->hourly()
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->runInBackground();

        // Sync visitor checkout every hour to keep security data up to date
        $schedule->command('ams:sync-visitor-checkout')
            ->hourly()
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->runInBackground();

        // Update arrival status (ontime/delay/advance) every hour
        $schedule->command('ams:update-arrival-status')
            ->hourly()
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping()
            ->runInBackground();

        // Update delivery compliance status at midnight (00:00) daily
        $schedule->command('ams:update-delivery-compliance')
            ->dailyAt('00:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
