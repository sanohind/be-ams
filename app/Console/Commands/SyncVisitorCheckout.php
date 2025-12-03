<?php

namespace App\Console\Commands;

use App\Services\VisitorSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        try {
        $dateOption = $this->option('date');
        $date = null;

        if ($dateOption) {
            try {
                $date = Carbon::parse($dateOption, 'Asia/Jakarta')->startOfDay();
            } catch (\Exception $e) {
                $this->error("Invalid date format provided. Please use YYYY-MM-DD.");
                Log::error('SyncVisitorCheckout: Invalid date format', [
                    'date' => $dateOption,
                    'error' => $e->getMessage()
                ]);
                return Command::FAILURE;
            }
        } else {
            $date = Carbon::now('Asia/Jakarta')->startOfDay();
            $this->info("No date provided. Defaulting to {$date->toDateString()} (Asia/Jakarta).");
        }

        $this->info('Starting visitor checkout sync...');
        $this->info("Syncing for date: {$date->toDateString()}");
        Log::info('SyncVisitorCheckout: Starting sync', ['date' => $date->toDateString()]);

            $result = $visitorSyncService->syncSecurityCheckout($date);

            $this->line("Processed : {$result['processed']}");
            $this->line("Updated   : {$result['updated']}");
            $this->line("Skipped   : {$result['skipped']}");
            $this->line("Unmatched : {$result['unmatched']}");

            // Log results
            Log::info('SyncVisitorCheckout: Sync completed', [
                'processed' => $result['processed'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'unmatched' => $result['unmatched'],
                'date' => $date ? $date->toDateString() : 'all'
            ]);

            if ($result['updated'] > 0) {
                $this->info('Visitor checkout sync completed successfully.');
            } else {
                $this->comment('Visitor checkout sync completed. No records were updated.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $errorMsg = "Error syncing visitor checkout: " . $e->getMessage();
            $this->error($errorMsg);
            Log::error('SyncVisitorCheckout: Exception occurred', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return Command::FAILURE;
        }
    }
}

