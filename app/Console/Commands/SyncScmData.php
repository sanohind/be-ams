<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScmSyncService;

class SyncScmData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:sync-scm {--type=arrivals : Type of sync (arrivals only - business partners are queried directly from SCM)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync SCM arrival transactions to AMS database';

    protected $syncService;

    /**
     * Create a new command instance.
     */
    public function __construct(ScmSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');
        
        $this->info("Starting SCM sync process...");
        $this->info("Sync type: {$type}");

        $results = [];

        try {
            // Only sync arrival transactions
            // Business partners are queried directly from SCM database by frontend
            if ($type !== 'arrivals') {
                $this->warn("Note: Only 'arrivals' type is supported. Business partners are queried directly from SCM.");
                $this->warn("Proceeding with arrivals sync...");
            }
            
            $this->info('Syncing arrival transactions...');
            $results['arrivals'] = $this->syncService->syncArrivalTransactions();

            // Display results
            $this->displayResults($results);

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return 1;
        }

        $this->info('SCM sync process completed!');
        return 0;
    }

    /**
     * Display sync results
     */
    protected function displayResults($results)
    {
        foreach ($results as $type => $result) {
            $this->line('');
            $this->info("=== {$type} Sync Results ===");
            
            if ($result['success']) {
                $this->info("✓ Success: {$result['records_synced']} records synced");
            } else {
                $this->error("✗ Failed");
            }
            
            if (!empty($result['errors'])) {
                $this->warn('Errors:');
                foreach ($result['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }
        }
    }
}
