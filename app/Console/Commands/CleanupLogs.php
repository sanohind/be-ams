<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SyncLog;
use Carbon\Carbon;

class CleanupLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:cleanup-logs {--days=30 : Number of days to keep logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old sync logs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Cleaning up sync logs older than {$days} days (before {$cutoffDate->toDateString()})");

        try {
            $deletedCount = SyncLog::where('created_at', '<', $cutoffDate)->delete();
            
            $this->info("✓ Cleaned up {$deletedCount} old sync log records");

        } catch (\Exception $e) {
            $this->error('Failed to cleanup logs: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
