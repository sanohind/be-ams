<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\DnScanSession;
use App\Services\AuthService;
use Carbon\Carbon;

class CheckSheetController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get arrivals available for check sheet
     */
    public function index(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $arrivals = ArrivalTransaction::forDate($date)
            ->whereNotNull('warehouse_checkin_time')
            ->whereNull('warehouse_checkout_time')
            ->with(['scanSessions', 'schedule'])
            ->get()
            ->groupBy(function ($arrival) {
                return $arrival->plan_delivery_date . '_' . $arrival->plan_delivery_time;
            });

        $groupedArrivals = [];

        foreach ($arrivals as $key => $group) {
            $firstArrival = $group->first();
            
            // Build DN-level rows for this group
            $dnRows = [];
            foreach ($group as $arrival) {
                $session = $arrival->scanSessions->first();
                $dnRows[] = [
                    'arrival_id' => $arrival->id,
                    'supplier_name' => $this->getSupplierName($arrival->bp_code),
                    'dn_number' => $arrival->dn_number,
                    'schedule' => $arrival->schedule && $arrival->schedule->arrival_time ? Carbon::parse($arrival->schedule->arrival_time)->format('H:i') : null,
                    'driver_name' => $arrival->driver_name,
                    'vehicle_plate' => $arrival->vehicle_plate,
                    'dock' => $arrival->schedule ? $arrival->schedule->dock : null,
                    'label_part_status' => $session ? $session->label_part_status : 'PENDING',
                    'coa_msds_status' => $session ? $session->coa_msds_status : 'PENDING',
                    'packing_condition_status' => $session ? $session->packing_condition_status : 'PENDING',
                ];
            }

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
                'check_sheet_status' => $this->getCheckSheetStatus($group),
                'arrival_ids' => $group->pluck('id')->toArray(),
                'rows' => $dnRows,
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
     * Submit check sheet for DN
     */
    public function submit(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_id' => 'required|exists:arrival_transactions,id',
            'dn_number' => 'required|string|max:25',
            'check_sheet_data' => 'required|array',
            'check_sheet_data.label_part' => 'required|in:OK,NOT_OK',
            'check_sheet_data.coa_msds' => 'required|in:OK,NOT_OK',
            'check_sheet_data.packing_condition' => 'required|in:OK,NOT_OK',
            'check_sheet_data.remarks' => 'nullable|string|max:500',
        ]);

        $arrival = ArrivalTransaction::findOrFail($request->arrival_id);

        // Find the scan session for this DN
        $scanSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $request->dn_number)
            ->first();

        if (!$scanSession) {
            return response()->json([
                'success' => false,
                'message' => 'Scan session not found for this DN'
            ], 404);
        }

        // Update scan session with check sheet data
        $scanSession->update([
            'label_part_status' => $request->check_sheet_data['label_part'],
            'coa_msds_status' => $request->check_sheet_data['coa_msds'],
            'packing_condition_status' => $request->check_sheet_data['packing_condition'],
        ]);

        // Store check sheet data in settings (for audit trail)
        $checkSheetKey = "check_sheet_{$arrival->id}_{$request->dn_number}";
        \App\Models\Setting::setValue(
            $checkSheetKey,
            json_encode([
                'dn_number' => $request->dn_number,
                'check_sheet_data' => $request->check_sheet_data,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]),
            "Check Sheet for DN {$request->dn_number}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Check sheet submitted successfully',
            'data' => [
                'dn_number' => $request->dn_number,
                'check_sheet_data' => $request->check_sheet_data,
                'submitted_by' => $user->name,
                'submitted_at' => now(),
            ]
        ]);
    }

    /**
     * Get check sheet details for DN
     */
    public function getDetails(Request $request)
    {
        $request->validate([
            'arrival_id' => 'required|exists:arrival_transactions,id',
            'dn_number' => 'required|string|max:25',
        ]);

        $arrival = ArrivalTransaction::findOrFail($request->arrival_id);

        // Get scan session
        $scanSession = DnScanSession::where('arrival_id', $arrival->id)
            ->where('dn_number', $request->dn_number)
            ->with(['scannedItems'])
            ->first();

        if (!$scanSession) {
            return response()->json([
                'success' => false,
                'message' => 'Scan session not found for this DN'
            ], 404);
        }

        // Get check sheet data from settings
        $checkSheetKey = "check_sheet_{$arrival->id}_{$request->dn_number}";
        $checkSheetData = \App\Models\Setting::getValue($checkSheetKey);

        $checkSheet = null;
        if ($checkSheetData) {
            $checkSheet = json_decode($checkSheetData, true);
        }

        // Get scanned items summary
        $scannedItems = $scanSession->scannedItems->map(function ($item) {
            return [
                'part_no' => $item->part_no,
                'scanned_quantity' => $item->scanned_quantity,
                'expected_quantity' => $item->expected_quantity,
                'match_status' => $item->match_status,
                'lot_number' => $item->lot_number,
                'customer' => $item->customer,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'dn_number' => $request->dn_number,
                'scan_session' => [
                    'session_start' => $scanSession->session_start,
                    'session_end' => $scanSession->session_end,
                    'total_items_scanned' => $scanSession->total_items_scanned,
                    'status' => $scanSession->status,
                ],
                'quality_checks' => [
                    'label_part_status' => $scanSession->label_part_status,
                    'coa_msds_status' => $scanSession->coa_msds_status,
                    'packing_condition_status' => $scanSession->packing_condition_status,
                ],
                'check_sheet' => $checkSheet,
                'scanned_items' => $scannedItems,
                'summary' => [
                    'total_items' => $scannedItems->count(),
                    'matched_items' => $scannedItems->where('match_status', 'matched')->count(),
                    'mismatched_items' => $scannedItems->where('match_status', '!=', 'matched')->count(),
                    'total_scanned_quantity' => $scannedItems->sum('scanned_quantity'),
                    'total_expected_quantity' => $scannedItems->sum('expected_quantity'),
                ]
            ]
        ]);
    }

    /**
     * Get check sheet statistics
     */
    public function getStatistics(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $arrivals = ArrivalTransaction::forDate($date)
            ->whereNotNull('warehouse_checkin_time')
            ->get();

        $totalArrivals = $arrivals->count();
        $withScanSessions = $arrivals->filter(function ($arrival) {
            return $arrival->scanSessions->isNotEmpty();
        })->count();

        $completedCheckSheets = 0;
        $pendingCheckSheets = 0;

        foreach ($arrivals as $arrival) {
            foreach ($arrival->scanSessions as $session) {
                if ($session->isQualityCheckCompleted()) {
                    $completedCheckSheets++;
                } else {
                    $pendingCheckSheets++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_arrivals' => $totalArrivals,
                'with_scan_sessions' => $withScanSessions,
                'completed_check_sheets' => $completedCheckSheets,
                'pending_check_sheets' => $pendingCheckSheets,
                'completion_rate' => ($completedCheckSheets + $pendingCheckSheets) > 0 
                    ? round(($completedCheckSheets / ($completedCheckSheets + $pendingCheckSheets)) * 100, 2) 
                    : 0,
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

    protected function getCheckSheetStatus($group)
    {
        $sessions = $group->flatMap->scanSessions;
        
        if ($sessions->isEmpty()) {
            return 'not_started';
        }
        
        $completedCheckSheets = $sessions->filter(function ($session) {
            return $session->isQualityCheckCompleted();
        })->count();
        
        $totalSessions = $sessions->count();
        
        if ($completedCheckSheets === $totalSessions) {
            return 'completed';
        } elseif ($completedCheckSheets > 0) {
            return 'in_progress';
        } else {
            return 'pending';
        }
    }
}
