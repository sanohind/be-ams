<?php

namespace App\Console\Commands;

use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncVisitorCheckout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:sync-visitor-checkout {--date= : Sync only arrivals for the specified date (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync security checkout time in arrival transactions from Visitor database';

    /**
     * Execute the console command.
     */
    public function handle(VisitorSyncService $visitorSyncService): int
    {
        $dateOption = $this->option('date');
        $date = null;

        if ($dateOption) {
            try {
                $date = Carbon::parse($dateOption)->startOfDay();
            } catch (\Exception $e) {
                $this->error("Invalid date format provided. Please use YYYY-MM-DD.");
                return Command::FAILURE;
            }
        }

        $this->info('Starting visitor checkout sync...');

        $result = $visitorSyncService->syncSecurityCheckout($date);

        $this->line("Processed : {$result['processed']}");
        $this->line("Updated   : {$result['updated']}");
        $this->line("Skipped   : {$result['skipped']}");
        $this->line("Unmatched : {$result['unmatched']}");

        if ($result['updated'] > 0) {
            $this->info('Visitor checkout sync completed successfully.');
        } else {
            $this->comment('Visitor checkout sync completed. No records were updated.');
        }

        return Command::SUCCESS;
    }
}

