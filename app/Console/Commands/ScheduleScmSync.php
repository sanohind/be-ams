<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ScmSyncService;

class ScheduleScmSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:schedule-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Schedule SCM sync process';

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
        $this->info("Starting scheduled SCM sync...");
        
        try {
            // Sync arrival transactions
            $this->info('Syncing arrival transactions...');
            $arrivalResult = $this->syncService->syncArrivalTransactions();
            
            if ($arrivalResult['success']) {
                $this->info("✓ Arrival transactions synced: {$arrivalResult['records_synced']} records");
            } else {
                $this->error("✗ Arrival transactions sync failed");
                if (!empty($arrivalResult['errors'])) {
                    foreach ($arrivalResult['errors'] as $error) {
                        $this->line("  - {$error}");
                    }
                }
            }
            
            // Sync business partners
            $this->info('Syncing business partners...');
            $partnerResult = $this->syncService->syncBusinessPartners();
            
            if ($partnerResult['success']) {
                $this->info("✓ Business partners synced: {$partnerResult['records_synced']} records");
            } else {
                $this->error("✗ Business partners sync failed");
                if (!empty($partnerResult['errors'])) {
                    foreach ($partnerResult['errors'] as $error) {
                        $this->line("  - {$error}");
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->error('Scheduled sync failed: ' . $e->getMessage());
            return 1;
        }

        $this->info('Scheduled SCM sync completed!');
        return 0;
    }
}
