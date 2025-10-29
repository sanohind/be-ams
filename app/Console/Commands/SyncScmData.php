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
    protected $signature = 'ams:sync-scm {--type=all : Type of sync (arrivals, partners, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync SCM data to AMS database';

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
            switch ($type) {
                case 'arrivals':
                    $this->info('Syncing arrival transactions...');
                    $results['arrivals'] = $this->syncService->syncArrivalTransactions();
                    break;
                    
                case 'partners':
                    $this->info('Syncing business partners...');
                    $results['partners'] = $this->syncService->syncBusinessPartners();
                    break;
                    
                case 'all':
                default:
                    $this->info('Syncing arrival transactions...');
                    $results['arrivals'] = $this->syncService->syncArrivalTransactions();
                    
                    $this->info('Syncing business partners...');
                    $results['partners'] = $this->syncService->syncBusinessPartners();
                    break;
            }

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
