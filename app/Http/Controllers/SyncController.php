<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ScmSyncService;
use App\Services\VisitorSyncService;
use App\Models\SyncLog;
use Carbon\Carbon;

class SyncController extends Controller
{
    protected $syncService;
    protected $visitorSyncService;

    public function __construct(ScmSyncService $syncService, VisitorSyncService $visitorSyncService)
    {
        $this->syncService = $syncService;
        $this->visitorSyncService = $visitorSyncService;
    }

    /**
     * Sync SCM arrival transactions
     */
    public function syncArrivalTransactions()
    {
        $result = $this->syncService->syncArrivalTransactions();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] 
                ? "Successfully synced {$result['records_synced']} arrival transactions"
                : 'Sync failed',
            'data' => $result
        ], $result['success'] ? 200 : 500);
    }

    /**
     * Sync SCM business partners
     */
    public function syncBusinessPartners()
    {
        $result = $this->syncService->syncBusinessPartners();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] 
                ? "Successfully synced {$result['records_synced']} business partners"
                : 'Sync failed',
            'data' => $result
        ], $result['success'] ? 200 : 500);
    }

    /**
     * Get sync statistics
     */
    public function getStatistics(Request $request)
    {
        $days = $request->get('days', 7);
        $statistics = $this->syncService->getSyncStatistics($days);

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Get sync logs
     */
    public function getLogs(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:success,failed,partial',
            'days' => 'nullable|integer|min:1|max:30',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SyncLog::query();

        if ($request->status) {
            $query->where('sync_status', $request->status);
        }

        if ($request->days) {
            $query->where('created_at', '>=', Carbon::now()->subDays($request->days));
        }

        $perPage = $request->get('per_page', 20);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'logs' => $logs->items(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                ]
            ]
        ]);
    }

    /**
     * Get last sync status
     */
    public function getLastSync()
    {
        $lastSync = $this->syncService->getLastSyncLog();

        if (!$lastSync) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No sync logs found'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sync_status' => $lastSync->sync_status,
                'records_synced' => $lastSync->records_synced,
                'error_message' => $lastSync->error_message,
                'started_at' => $lastSync->started_at,
                'completed_at' => $lastSync->completed_at,
                'duration' => $lastSync->duration,
                'created_at' => $lastSync->created_at,
            ]
        ]);
    }

    /**
     * Manual sync trigger (for testing)
     */
    public function manualSync(Request $request)
    {
        $request->validate([
            'type' => 'required|in:arrivals,partners,visitor_checkout,all',
            'date' => 'nullable|date',
        ]);

        $results = [];
        $date = $request->has('date') ? Carbon::parse($request->get('date')) : null;

        switch ($request->type) {
            case 'arrivals':
                $results['arrivals'] = $this->syncService->syncArrivalTransactions();
                break;
            case 'partners':
                $results['partners'] = $this->syncService->syncBusinessPartners();
                break;
            case 'visitor_checkout':
                $results['visitor_checkout'] = $this->visitorSyncService->syncSecurityCheckout($date);
                break;
            case 'all':
                $results['arrivals'] = $this->syncService->syncArrivalTransactions();
                $results['partners'] = $this->syncService->syncBusinessPartners();
                $results['visitor_checkout'] = $this->visitorSyncService->syncSecurityCheckout($date);
                break;
        }

        $overallSuccess = collect($results)->every(function ($result) {
            return $result['success'];
        });

        return response()->json([
            'success' => $overallSuccess,
            'message' => $overallSuccess ? 'Manual sync completed successfully' : 'Manual sync completed with errors',
            'data' => $results
        ], $overallSuccess ? 200 : 207); // 207 Multi-Status for partial success
    }

    /**
     * Sync visitor checkout data.
     */
    public function syncVisitorCheckout(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->has('date') ? Carbon::parse($request->get('date')) : null;

        $result = $this->visitorSyncService->syncSecurityCheckout($date);

        return response()->json([
            'success' => true,
            'message' => $result['updated'] > 0
                ? "Synced visitor checkout for {$result['updated']} arrivals"
                : 'No arrivals required visitor checkout updates',
            'data' => $result,
        ]);
    }
}
