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
        // Daily SCM sync at 1:00 AM
        $schedule->command('ams:schedule-sync')
            ->dailyAt('01:00')
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
