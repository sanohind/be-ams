<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\DnScanSession;
use App\Models\ScannedItem;
use App\Models\External\ScmDnDetail;
use App\Services\AuthService;
use Carbon\Carbon;

class ItemScanController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get arrivals available for scanning
     */
    public function index(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $arrivals = ArrivalTransaction::forDate($date)
            ->whereNotNull('warehouse_checkin_time')
            ->whereNull('warehouse_checkout_time')
            ->with(['scanSessions'])
            ->get()
            ->groupBy(function ($arrival) {
                return $arrival->plan_delivery_date . '_' . $arrival->plan_delivery_time;
            });

        $groupedArrivals = [];

        foreach ($arrivals as $key => $group) {
            $firstArrival = $group->first();
            
            $groupedArrivals[] = [
                'group_key' => $key,
                'supplier_name' => $this->getSupplierName($firstArrival->bp_code),
                'bp_code' => $firstArrival->bp_code,
                'driver_name' => $firstArrival->driver_name,
                'vehicle_plate' => $firstArrival->vehicle_plate,
                'warehouse_checkin_time' => $firstArrival->warehouse_checkin_time,
                'dn_count' => $group->count(),
                'dn_numbers' => $group->pluck('dn_number')->toArray(),
                'scan_status' => $this->getGroupScanStatus($group),
                'arrival_ids' => $group->pluck('id')->toArray(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'arrivals' => $groupedArrivals,
            ]
        ]);
    }

    /**
     * Start scanning session for DN
     */
    public function startSession(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_id' => 'required|exists:arrival_transactions,id',
            'dn_number' => 'required|string|max:25',
        ]);

        $arrival = ArrivalTransaction::findOrFail($request->arrival_id);

        // Check if session already exists for this DN
        $existingSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $request->dn_number)
            ->where('status', 'in_progress')
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => false,
                'message' => 'Scanning session already in progress for this DN'
            ], 400);
        }

        $session = DnScanSession::create([
            'arrival_id' => $arrival->id,
            'dn_number' => $request->dn_number,
            'operator_id' => $user->id,
            'session_start' => now(),
            'status' => 'in_progress',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scanning session started successfully',
            'data' => $session
        ], 201);
    }

    /**
     * Scan DN QR code
     */
    public function scanDn(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_id' => 'required|exists:arrival_transactions,id',
            'qr_data' => 'required|string',
        ]);

        $arrival = ArrivalTransaction::findOrFail($request->arrival_id);

        // Parse DN QR data (format: DN0030176)
        $dnNumber = $this->parseDnQrData($request->qr_data);

        if (!$dnNumber) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid DN QR code format'
            ], 400);
        }

        // Verify DN belongs to this arrival
        if ($arrival->dn_number !== $dnNumber) {
            return response()->json([
                'success' => false,
                'message' => 'DN does not match this arrival'
            ], 400);
        }

        // Check if session already exists for this DN
        $existingSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $dnNumber)
            ->where('status', 'in_progress')
            ->first();

        if ($existingSession) {
            return response()->json([
                'success' => false,
                'message' => 'Scanning session already in progress for this DN'
            ], 400);
        }

        // Create new scanning session
        $session = DnScanSession::create([
            'arrival_id' => $arrival->id,
            'dn_number' => $dnNumber,
            'operator_id' => $user->id,
            'session_start' => now(),
            'status' => 'in_progress',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'DN scanned successfully, ready for item scanning',
            'data' => [
                'session' => $session,
                'dn_number' => $dnNumber,
            ]
        ]);
    }

    /**
     * Scan item QR code
     */
    public function scanItem(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'session_id' => 'required|exists:dn_scan_sessions,id',
            'qr_data' => 'required|string',
        ]);

        $session = DnScanSession::findOrFail($request->session_id);

        if ($session->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Session is not in progress'
            ], 400);
        }

        // Parse item QR data
        $qrData = $this->parseItemQrData($request->qr_data);

        if (!$qrData) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid item QR code format'
            ], 400);
        }

        // Verify DN matches the session
        if ($qrData['dn_number'] !== $session->dn_number) {
            return response()->json([
                'success' => false,
                'message' => 'Item DN does not match session DN'
            ], 400);
        }

        // Get expected quantity from SCM
        $expectedQuantity = $this->getExpectedQuantity($session->dn_number, $qrData['part_no']);

        // Check if item already scanned
        $existingItem = ScannedItem::where('session_id', $session->id)
            ->where('part_no', $qrData['part_no'])
            ->where('lot_number', $qrData['lot_number'])
            ->first();

        if ($existingItem) {
            return response()->json([
                'success' => false,
                'message' => 'Item with this part number and lot number already scanned'
            ], 400);
        }

        // Create scanned item record
        $scannedItem = ScannedItem::create([
            'session_id' => $session->id,
            'arrival_id' => $session->arrival_id,
            'dn_number' => $session->dn_number,
            'part_no' => $qrData['part_no'],
            'scanned_quantity' => $qrData['quantity'],
            'lot_number' => $qrData['lot_number'],
            'customer' => $qrData['customer'],
            'qr_raw_data' => $request->qr_data,
            'expected_quantity' => $expectedQuantity,
            'scanned_by' => $user->id,
            'scanned_at' => now(),
        ]);

        // Update match status
        $scannedItem->updateMatchStatus();

        // Update session statistics
        $session->increment('total_items_scanned');

        return response()->json([
            'success' => true,
            'message' => 'Item scanned successfully',
            'data' => [
                'scanned_item' => $scannedItem,
                'match_status' => $scannedItem->match_status,
                'quantity_variance' => $scannedItem->quantity_variance,
            ]
        ]);
    }

    /**
     * Complete scanning session
     */
    public function completeSession(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'session_id' => 'required|exists:dn_scan_sessions,id',
            'label_part_status' => 'required|in:OK,NOT_OK',
            'coa_msds_status' => 'required|in:OK,NOT_OK',
            'packing_condition_status' => 'required|in:OK,NOT_OK',
        ]);

        $session = DnScanSession::findOrFail($request->session_id);

        if ($session->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Session is not in progress'
            ], 400);
        }

        $session->update([
            'label_part_status' => $request->label_part_status,
            'coa_msds_status' => $request->coa_msds_status,
            'packing_condition_status' => $request->packing_condition_status,
        ]);

        $session->endSession();

        return response()->json([
            'success' => true,
            'message' => 'Scanning session completed successfully',
            'data' => [
                'session' => $session,
                'total_items_scanned' => $session->total_items_scanned,
                'session_duration' => $session->session_duration,
            ]
        ]);
    }

    /**
     * Get scanning session details
     */
    public function getSessionDetails($sessionId)
    {
        $session = DnScanSession::with(['scannedItems', 'arrival'])
            ->findOrFail($sessionId);

        $scannedItems = $session->scannedItems->map(function ($item) {
            return [
                'id' => $item->id,
                'part_no' => $item->part_no,
                'scanned_quantity' => $item->scanned_quantity,
                'expected_quantity' => $item->expected_quantity,
                'quantity_variance' => $item->quantity_variance,
                'match_status' => $item->match_status,
                'lot_number' => $item->lot_number,
                'customer' => $item->customer,
                'scanned_at' => $item->scanned_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'scanned_items' => $scannedItems,
                'summary' => [
                    'total_items_scanned' => $session->total_items_scanned,
                    'total_scanned_quantity' => $session->total_scanned_quantity,
                    'total_expected_quantity' => $session->total_expected_quantity,
                    'matched_items' => $scannedItems->where('match_status', 'matched')->count(),
                    'mismatched_items' => $scannedItems->where('match_status', '!=', 'matched')->count(),
                ]
            ]
        ]);
    }

    /**
     * Get scanning statistics
     */
    public function getStatistics(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $sessions = DnScanSession::whereHas('arrival', function ($query) use ($date) {
            $query->where('plan_delivery_date', $date);
        })->get();

        $totalSessions = $sessions->count();
        $completedSessions = $sessions->where('status', 'completed')->count();
        $inProgressSessions = $sessions->where('status', 'in_progress')->count();
        $totalItemsScanned = $sessions->sum('total_items_scanned');

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_sessions' => $totalSessions,
                'completed_sessions' => $completedSessions,
                'in_progress_sessions' => $inProgressSessions,
                'total_items_scanned' => $totalItemsScanned,
                'completion_rate' => $totalSessions > 0 ? round(($completedSessions / $totalSessions) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Helper methods
     */
    protected function getSupplierName($bpCode)
    {
        $supplierData = \App\Models\Setting::getValue("supplier_{$bpCode}");
        if ($supplierData) {
            $data = json_decode($supplierData, true);
            return $data['name'] ?? $bpCode;
        }
        return $bpCode;
    }

    protected function getGroupScanStatus($group)
    {
        $sessions = $group->flatMap->scanSessions;
        
        if ($sessions->isEmpty()) {
            return 'not_started';
        }
        
        $completedSessions = $sessions->where('status', 'completed')->count();
        $totalSessions = $sessions->count();
        
        if ($completedSessions === $totalSessions) {
            return 'completed';
        } elseif ($completedSessions > 0) {
            return 'in_progress';
        } else {
            return 'not_started';
        }
    }

    /**
     * Parse DN QR code data
     * Format: DN0030176
     */
    protected function parseDnQrData($qrData)
    {
        // Remove any whitespace
        $qrData = trim($qrData);
        
        // Check if it starts with DN and has valid format
        if (preg_match('/^DN\d+$/', $qrData)) {
            return $qrData;
        }
        
        return null;
    }

    /**
     * Parse item QR code data
     * Format: RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4
     * Part Number;Quantity;Lot Number;Customer;Field5;Field6;DN Number;Field8
     */
    protected function parseItemQrData($qrData)
    {
        // Remove any whitespace
        $qrData = trim($qrData);
        
        // Split by semicolon
        $parts = explode(';', $qrData);
        
        // Must have at least 7 parts
        if (count($parts) < 7) {
            return null;
        }

        return [
            'part_no' => $parts[0] ?? null,
            'quantity' => (int) ($parts[1] ?? 0),
            'lot_number' => $parts[2] ?? null,
            'customer' => !empty($parts[3]) ? $parts[3] : null, // Can be empty
            'field5' => $parts[4] ?? null,
            'field6' => $parts[5] ?? null,
            'dn_number' => $parts[6] ?? null,
            'field8' => $parts[7] ?? null,
        ];
    }

    protected function getExpectedQuantity($dnNumber, $partNo)
    {
        $dnDetail = ScmDnDetail::forDn($dnNumber)
            ->forPart($partNo)
            ->first();

        return $dnDetail ? $dnDetail->dn_qty : 0;
    }
}
