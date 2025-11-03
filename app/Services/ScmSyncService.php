<?php

namespace App\Services;

use App\Models\ArrivalTransaction;
use App\Models\SyncLog;
use App\Models\External\ScmDnHeader;
use App\Models\External\ScmBusinessPartner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScmSyncService
{
    protected $syncLog;

    /**
     * Sync SCM data to AMS arrival transactions
     */
    public function syncArrivalTransactions(): array
    {
        $this->syncLog = SyncLog::createLog();
        $this->syncLog->markAsStarted();

        $result = [
            'success' => false,
            'records_synced' => 0,
            'total_created' => 0,
            'total_updated' => 0,
            'errors' => []
        ];

        try {
            DB::beginTransaction();

            // Get open DN headers from SCM
            $scmDnHeaders = ScmDnHeader::open()
                ->where('plan_delivery_date', '>=', Carbon::today())
                ->get();

            $syncedCount = 0;
            $createdCount = 0;
            $updatedCount = 0;
            $errors = [];

            foreach ($scmDnHeaders as $dnHeader) {
                try {
                    // Check if arrival transaction already exists by composite (dn_number, po_number)
                    $existingTransaction = ArrivalTransaction::where('dn_number', $dnHeader->no_dn)
                        ->where('po_number', $dnHeader->po_no)
                        ->first();

                    if ($existingTransaction) {
                        // Update existing transaction if needed
                        $this->updateArrivalTransaction($existingTransaction, $dnHeader);
                        $updatedCount++;
                    } else {
                        // Create new arrival transaction
                        $this->createArrivalTransaction($dnHeader);
                        $createdCount++;
                    }

                    $syncedCount++;

                } catch (\Exception $e) {
                    $errors[] = "Error syncing DN {$dnHeader->no_dn}: " . $e->getMessage();
                    Log::error("SCM Sync Error for DN {$dnHeader->no_dn}", [
                        'error' => $e->getMessage(),
                        'dn_header' => $dnHeader->toArray()
                    ]);
                }
            }

            DB::commit();

            $result['success'] = true;
            $result['records_synced'] = $syncedCount;
            $result['total_created'] = $createdCount;
            $result['total_updated'] = $updatedCount;
            $result['errors'] = $errors;

            $this->syncLog->markAsCompleted(
                empty($errors) ? 'success' : 'partial',
                $syncedCount,
                empty($errors) ? null : implode('; ', $errors)
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            $result['errors'][] = $e->getMessage();
            Log::error('SCM Sync Failed', ['error' => $e->getMessage()]);

            $this->syncLog->markAsCompleted('failed', 0, $e->getMessage());
        }

        return $result;
    }

    /**
     * Create new arrival transaction from SCM DN header
     */
    protected function createArrivalTransaction(ScmDnHeader $dnHeader): ArrivalTransaction
    {
        return ArrivalTransaction::create([
            'dn_number' => $dnHeader->no_dn,
            'po_number' => $dnHeader->po_no,
            'arrival_type' => 'regular',
            'plan_delivery_date' => $dnHeader->plan_delivery_date,
            'plan_delivery_time' => $dnHeader->plan_delivery_time,
            'bp_code' => $dnHeader->supplier_code,
            'driver_name' => $dnHeader->driver_name,
            'vehicle_plate' => $dnHeader->plat_number,
            'status' => 'pending',
        ]);
    }

    /**
     * Update existing arrival transaction with SCM data
     */
    protected function updateArrivalTransaction(ArrivalTransaction $transaction, ScmDnHeader $dnHeader): void
    {
        // Only update if it's a regular transaction (not additional)
        if ($transaction->arrival_type === 'regular') {
            $transaction->update([
                'po_number' => $dnHeader->po_no,
                'plan_delivery_date' => $dnHeader->plan_delivery_date,
                'plan_delivery_time' => $dnHeader->plan_delivery_time,
                'bp_code' => $dnHeader->supplier_code,
                'driver_name' => $dnHeader->driver_name,
                'vehicle_plate' => $dnHeader->plat_number,
            ]);
        }
    }

    /**
     * Sync business partners from SCM
     */
    public function syncBusinessPartners(): array
    {
        $this->syncLog = SyncLog::createLog();
        $this->syncLog->markAsStarted();

        $result = [
            'success' => false,
            'records_synced' => 0,
            'errors' => []
        ];

        try {
            $businessPartners = ScmBusinessPartner::suppliers()->active()->get();
            $syncedCount = 0;
            $errors = [];

            foreach ($businessPartners as $partner) {
                try {
                    // Update or create business partner data in settings
                    \App\Models\Setting::setValue(
                        "supplier_{$partner->bp_code}",
                        json_encode([
                            'name' => $partner->bp_name,
                            'status' => $partner->bp_status_desc,
                            'phone' => $partner->bp_phone,
                            'address' => $partner->adr_line_1,
                        ]),
                        "SCM Business Partner: {$partner->bp_name}"
                    );

                    $syncedCount++;

                } catch (\Exception $e) {
                    $errors[] = "Error syncing BP {$partner->bp_code}: " . $e->getMessage();
                }
            }

            $result['success'] = true;
            $result['records_synced'] = $syncedCount;
            $result['errors'] = $errors;

            $this->syncLog->markAsCompleted(
                empty($errors) ? 'success' : 'partial',
                $syncedCount,
                empty($errors) ? null : implode('; ', $errors)
            );

        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
            $this->syncLog->markAsCompleted('failed', 0, $e->getMessage());
        }

        return $result;
    }

    /**
     * Get sync statistics
     */
    public function getSyncStatistics(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);

        $logs = SyncLog::where('created_at', '>=', $startDate)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalSyncs = $logs->count();
        $successfulSyncs = $logs->where('sync_status', 'success')->count();
        $failedSyncs = $logs->where('sync_status', 'failed')->count();
        $partialSyncs = $logs->where('sync_status', 'partial')->count();
        $totalRecordsSynced = $logs->sum('records_synced');

        return [
            'total_syncs' => $totalSyncs,
            'successful_syncs' => $successfulSyncs,
            'failed_syncs' => $failedSyncs,
            'partial_syncs' => $partialSyncs,
            'success_rate' => $totalSyncs > 0 ? round(($successfulSyncs / $totalSyncs) * 100, 2) : 0,
            'total_records_synced' => $totalRecordsSynced,
            'average_records_per_sync' => $totalSyncs > 0 ? round($totalRecordsSynced / $totalSyncs, 2) : 0,
            'last_sync' => $logs->first()?->created_at,
        ];
    }

    /**
     * Get last sync log
     */
    public function getLastSyncLog(): ?SyncLog
    {
        return SyncLog::orderBy('created_at', 'desc')->first();
    }
}
