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
     * Scan DN QR code - NEW: Search by DN number only (no arrival_id required)
     */
    public function scanDn(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'dn_number' => 'required|string|max:25',
        ]);

        // Parse DN number (can be from QR scan or direct input)
        $dnNumber = $this->parseDnQrData($request->dn_number);
        
        // If parsing fails, try using the input directly
        if (!$dnNumber) {
            $dnNumber = trim($request->dn_number);
        }

        // Find arrival transaction by DN number
        $arrival = ArrivalTransaction::where('dn_number', $dnNumber)->first();

        if (!$arrival) {
            return response()->json([
                'success' => false,
                'message' => 'DN number not found in arrival transactions'
            ], 404);
        }

        // Check if a completed session already exists for this DN
        $completedSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $dnNumber)
            ->where('status', 'completed')
            ->latest('session_end')
            ->first();

        if ($completedSession) {
            return response()->json([
                'success' => false,
                'message' => 'DN number (' . $dnNumber . ') has already been completely scanned. Please verify the DN or contact administrator to reopen the session.',
                'data' => [
                    'session' => $completedSession,
                ]
            ], 409);
        }

        // Check if session already exists for this DN
        $existingSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $dnNumber)
            ->where('status', 'in_progress')
            ->first();

        if ($existingSession) {
            // Return existing session with DN details
            return response()->json([
                'success' => true,
                'message' => 'Scanning session already in progress for this DN',
                'data' => [
                    'session' => $existingSession,
                    'dn_number' => $dnNumber,
                    'arrival_id' => $arrival->id,
                    'items' => $this->getDnItems($dnNumber),
                ]
            ]);
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
                'arrival_id' => $arrival->id,
                'items' => $this->getDnItems($dnNumber),
            ]
        ]);
    }

    /**
     * Get DN items from SCM DN Detail
     */
    public function getDnItemsList(Request $request)
    {
        $request->validate([
            'dn_number' => 'required|string|max:25',
        ]);

        $dnNumber = trim($request->dn_number);
        
        // Verify DN exists in arrival_transactions
        $arrival = ArrivalTransaction::where('dn_number', $dnNumber)->first();

        if (!$arrival) {
            return response()->json([
                'success' => false,
                'message' => 'DN number not found in arrival transactions'
            ], 404);
        }

        $items = $this->getDnItems($dnNumber);

        return response()->json([
            'success' => true,
            'data' => [
                'dn_number' => $dnNumber,
                'arrival_id' => $arrival->id,
                'items' => $items,
            ]
        ]);
    }

    /**
     * Helper: Get DN items from SCM with progress
     */
    protected function getDnItems($dnNumber)
    {
        // Get items from SCM DN Detail
        $dnDetails = ScmDnDetail::forDn($dnNumber)->get();

        if ($dnDetails->isEmpty()) {
            return [];
        }

        // Get existing scanned items for this DN (if session exists)
        $session = DnScanSession::where('dn_number', $dnNumber)
            ->where('status', 'in_progress')
            ->first();

        $scannedItems = [];
        if ($session) {
            $scannedItems = ScannedItem::where('session_id', $session->id)
                ->get()
                ->groupBy('part_no')
                ->map(function ($items) {
                    return $items->sum('scanned_quantity');
                })
                ->toArray();
        }

        $items = [];
        $no = 1;
        foreach ($dnDetails as $detail) {
            $scannedQty = $scannedItems[$detail->part_no] ?? 0;
            $totalQty = $detail->dn_qty ?? 0;
            $progress = $totalQty > 0 ? round(($scannedQty / $totalQty) * 100, 2) : 0;

            $items[] = [
                'no' => $no++,
                'part_no' => $detail->part_no,
                'part_name' => $detail->item_desc_a ?? '-',
                'qty_per_box' => $detail->dn_snp ?? 0,
                'total_quantity' => $totalQty,
                'scanned_quantity' => $scannedQty,
                'progress' => $progress,
                'dn_detail_no' => $detail->dn_detail_no,
            ];
        }

        return $items;
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
                'message' => 'Invalid item QR code format. Expected format: part_no;quantity;lot_number;customer;field5;field6;dn_number;field8'
            ], 400);
        }

        // Validation 1: Check if DN number matches the active session DN
        if ($qrData['dn_number'] !== $session->dn_number) {
            return response()->json([
                'success' => false,
                'message' => 'DN number mismatch. Label DN (' . $qrData['dn_number'] . ') does not match active session DN (' . $session->dn_number . ')'
            ], 400);
        }

        // Validation 2: Check if part number exists in DN details
        $dnDetail = ScmDnDetail::forDn($session->dn_number)
            ->forPart($qrData['part_no'])
            ->first();

        if (!$dnDetail) {
            return response()->json([
                'success' => false,
                'message' => 'Part number (' . $qrData['part_no'] . ') not found in DN details'
            ], 404);
        }

        $requestedQuantity = (int) $qrData['quantity'];
        $totalQuantity = (int) ($dnDetail->dn_qty ?? 0);

        if ($requestedQuantity <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid quantity scanned for part number (' . $qrData['part_no'] . ')'
            ], 400);
        }

        $currentTotalForPart = ScannedItem::where('session_id', $session->id)
            ->where('part_no', $qrData['part_no'])
            ->sum('scanned_quantity');

        if ($currentTotalForPart + $requestedQuantity > $totalQuantity) {
            $remainingQuantity = max($totalQuantity - $currentTotalForPart, 0);

            return response()->json([
                'success' => false,
                'message' => 'Scanned quantity for part number (' . $qrData['part_no'] . ') exceeds required total. Remaining quantity: ' . $remainingQuantity,
                'data' => [
                    'part_no' => $qrData['part_no'],
                    'remaining_quantity' => $remainingQuantity,
                    'total_required' => $totalQuantity,
                    'attempted_quantity' => $requestedQuantity,
                ]
            ], 409);
        }

        // Validation 3: Check if lot number already scanned (lot number must be unique)
        // $existingItem = ScannedItem::where('session_id', $session->id)
        //     ->where('lot_number', $qrData['lot_number'])
        //     ->first();

        // if ($existingItem) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Lot number (' . $qrData['lot_number'] . ') has already been scanned. Each box must have a unique lot number.',
        //         'data' => [
        //             'existing_item' => [
        //                 'part_no' => $existingItem->part_no,
        //                 'lot_number' => $existingItem->lot_number,
        //                 'scanned_at' => $existingItem->scanned_at,
        //             ]
        //         ]
        //     ], 409); // 409 Conflict
        // }

        // All validations passed, create new scanned item record
        $scannedItem = ScannedItem::create([
            'session_id' => $session->id,
            'arrival_id' => $session->arrival_id,
            'dn_number' => $session->dn_number,
            'part_no' => $qrData['part_no'],
            'scanned_quantity' => $requestedQuantity,
            'total_quantity' => $totalQuantity,
            'lot_number' => $qrData['lot_number'],
            'customer' => $qrData['customer'] ?: null,
            'qr_raw_data' => $request->qr_data,
            'dn_detail_no' => $dnDetail->dn_detail_no,
            'scanned_by' => $user->id,
            'scanned_at' => now(),
        ]);

        // Update session statistics
        $session->increment('total_items_scanned');

        // Get updated progress for this part number
        $totalScanned = ScannedItem::where('session_id', $session->id)
            ->where('part_no', $qrData['part_no'])
            ->sum('scanned_quantity');
        $totalQuantity = $dnDetail->dn_qty ?? 0;
        $progress = $totalQuantity > 0 ? round(($totalScanned / $totalQuantity) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Item scanned successfully',
            'data' => [
                'scanned_item' => [
                    'id' => $scannedItem->id,
                    'part_no' => $scannedItem->part_no,
                    'scanned_quantity' => $scannedItem->scanned_quantity,
                    'total_quantity' => $scannedItem->total_quantity,
                    'lot_number' => $scannedItem->lot_number,
                    'customer' => $scannedItem->customer,
                    'scanned_at' => $scannedItem->scanned_at,
                ],
                'total_scanned' => $totalScanned,
                'total_quantity' => $totalQuantity,
                'progress' => $progress,
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
            'label_part_status' => 'sometimes|in:OK,NOT_OK',
            'coa_msds_status' => 'sometimes|in:OK,NOT_OK',
            'packing_condition_status' => 'sometimes|in:OK,NOT_OK',
            'completion_dn_number' => 'required|string|max:25',
        ]);

        $session = DnScanSession::findOrFail($request->session_id);

        if ($session->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Session is not in progress'
            ], 400);
        }

        $completionDn = $this->parseDnQrData($request->completion_dn_number) ?? trim($request->completion_dn_number);

        if (strcasecmp($completionDn, $session->dn_number) !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'DN confirmation mismatch. Scanned DN (' . $completionDn . ') does not match session DN (' . $session->dn_number . ')'
            ], 400);
        }

        $dnDetails = ScmDnDetail::forDn($session->dn_number)->get();

        if ($dnDetails->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve DN details for validation. Please try again or contact administrator.'
            ], 500);
        }

        $scannedTotals = ScannedItem::where('session_id', $session->id)
            ->select('part_no')
            ->selectRaw('SUM(scanned_quantity) as total_scanned')
            ->groupBy('part_no')
            ->pluck('total_scanned', 'part_no');

        $incompleteParts = [];
        $totalExpectedQuantity = 0;
        $totalScannedQuantity = 0;

        foreach ($dnDetails as $detail) {
            $expected = (int) ($detail->dn_qty ?? 0);
            $scanned = (int) ($scannedTotals[$detail->part_no] ?? 0);

            $totalExpectedQuantity += $expected;
            $totalScannedQuantity += $scanned;

            if ($expected > 0 && $scanned < $expected) {
                $incompleteParts[] = [
                    'part_no' => $detail->part_no,
                    'expected_quantity' => $expected,
                    'scanned_quantity' => $scanned,
                    'remaining_quantity' => $expected - $scanned,
                ];
            }
        }

        if (!empty($incompleteParts)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete session. Some parts have not been fully scanned.',
                'data' => [
                    'incomplete_parts' => $incompleteParts,
                ]
            ], 409);
        }

        if ($totalScannedQuantity < $totalExpectedQuantity) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete session. Total scanned quantity (' . $totalScannedQuantity . ') is less than required (' . $totalExpectedQuantity . ').'
            ], 409);
        }

        $updateData = [];

        if ($request->has('label_part_status')) {
            $updateData['label_part_status'] = $request->label_part_status;
        }

        if ($request->has('coa_msds_status')) {
            $updateData['coa_msds_status'] = $request->coa_msds_status;
        }

        if ($request->has('packing_condition_status')) {
            $updateData['packing_condition_status'] = $request->packing_condition_status;
        }

        if (!empty($updateData)) {
            $session->update($updateData);
        }

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
     * Mark arrival as incomplete quantity
     */
    public function markIncompleteQty(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_id' => 'required|exists:arrival_transactions,id',
        ]);

        $arrival = ArrivalTransaction::findOrFail($request->arrival_id);

        // Update delivery compliance to incomplete qty
        $arrival->markAsIncompleteQuantity();
        $arrival->save();

        return response()->json([
            'success' => true,
            'message' => 'Arrival marked as incomplete quantity successfully',
            'data' => [
                'arrival_id' => $arrival->id,
                'delivery_compliance' => $arrival->delivery_compliance,
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
                'total_quantity' => $item->total_quantity,
                'progress' => $item->progress,
                'lot_number' => $item->lot_number,
                'customer' => $item->customer,
                'scanned_at' => $item->scanned_at,
            ];
        });

        $totalScanned = $scannedItems->sum('scanned_quantity');
        $totalQuantity = $scannedItems->sum('total_quantity');

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session,
                'scanned_items' => $scannedItems,
                'summary' => [
                    'total_items_scanned' => $session->total_items_scanned,
                    'total_scanned_quantity' => $totalScanned,
                    'total_quantity' => $totalQuantity,
                    'overall_progress' => $totalQuantity > 0 ? round(($totalScanned / $totalQuantity) * 100, 2) : 0,
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
     * Format: RL1MR0SELANGFLEX1000;200;PL2502891111110236;;1;1;DN0049106;1
     * Index 0: Part Number (part_no)
     * Index 1: Quantity (scanned_quantity)
     * Index 2: Lot Number (lot_number) - must be unique
     * Index 3: Customer (customer) - can be empty
     * Index 4: Field 5 (unused)
     * Index 5: Field 6 (unused)
     * Index 6: DN Number (dn_number)
     * Index 7: Field 8 (unused)
     */
    protected function parseItemQrData($qrData)
    {
        // Remove any whitespace
        $qrData = trim($qrData);
        
        // Split by semicolon
        $parts = explode(';', $qrData);
        
        // Must have at least 7 parts (0-6, index 7 is optional)
        if (count($parts) < 7) {
            return null;
        }

        // Validate required fields
        if (empty($parts[0]) || empty($parts[1]) || empty($parts[2]) || empty($parts[6])) {
            return null;
        }

        return [
            'part_no' => trim($parts[0]),
            'quantity' => (int) ($parts[1] ?? 0),
            'lot_number' => trim($parts[2]),
            'customer' => !empty(trim($parts[3] ?? '')) ? trim($parts[3]) : null, // Can be empty
            'dn_number' => trim($parts[6]),
            'field5' => $parts[4] ?? null,
            'field6' => $parts[5] ?? null,
            'field8' => $parts[7] ?? null,
        ];
    }

}
