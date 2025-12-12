<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Models\DnScanSession;
use App\Models\ScannedItem;
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
     * Get check sheet history - shows all data based on arrival_schedule for the date
     */
    public function getHistory(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);

        // Get all schedules for the date (regular schedules for the day of week, additional schedules for the date)
        $dayName = strtolower($carbonDate->format('l')); // monday, tuesday, etc.
        
        $schedules = \App\Models\ArrivalSchedule::where(function ($query) use ($date, $dayName) {
            $query->where(function ($q) use ($dayName) {
                // Regular schedules for this day of week
                $q->where('arrival_type', 'regular')
                  ->where('day_name', $dayName);
            })->orWhere(function ($q) use ($date) {
                // Additional schedules for this specific date
                $q->where('arrival_type', 'additional')
                  ->whereDate('schedule_date', $date);
            });
        })
        ->orderBy('arrival_time', 'asc')
        ->orderBy('bp_code', 'asc')
        ->get();

        $historyData = [];
        $rowNumber = 1;

        foreach ($schedules as $schedule) {
            // Get all arrival transactions for this schedule on this date that have DN
            $arrivals = ArrivalTransaction::where('schedule_id', $schedule->id)
                ->whereDate('plan_delivery_date', $date)
                ->whereNotNull('dn_number')
                ->where('dn_number', '!=', '')
                ->with(['scanSessions'])
                ->get();

            // Only show if there are arrivals with DN
            if ($arrivals->isNotEmpty()) {
                // Group arrivals by DN number
                $arrivalsByDn = $arrivals->groupBy('dn_number');
                
                foreach ($arrivalsByDn as $dnNumber => $dnArrivals) {
                    $firstArrival = $dnArrivals->first();
                    $session = $firstArrival->scanSessions->first();
                    
                    // Get total qty from SCM dn_detail
                    $totalQtyDn = \App\Models\External\ScmDnDetail::where('no_dn', $dnNumber)
                        ->sum('dn_qty');
                    
                    // Get actual qty from scanned_items
                    $actualQty = 0;
                    $picName = null;
                    if ($session) {
                        $actualQty = \App\Models\ScannedItem::where('session_id', $session->id)
                            ->sum('scanned_quantity');
                        
                        // Get PIC name from operator_id
                        if ($session->operator_id) {
                            $picName = $this->getPicName($session->operator_id);
                        }
                    }

                    $historyData[] = [
                        'id' => $session ? $session->id : null,
                        'session_id' => $session ? $session->id : null,
                        'arrival_id' => $firstArrival->id,
                        'row_number' => $rowNumber++,
                        'dn_number' => $dnNumber,
                        'supplier_name' => $this->getSupplierName($schedule->bp_code),
                        'bp_code' => $schedule->bp_code,
                        'schedule' => $schedule->arrival_time 
                            ? Carbon::parse($schedule->arrival_time)->format('H:i') 
                            : null,
                        'actual_arrival_time' => $firstArrival->warehouse_checkin_time 
                            ? Carbon::parse($firstArrival->warehouse_checkin_time)->format('H:i') 
                            : null,
                        'driver_name' => $firstArrival->driver_name,
                        'vehicle_plate' => $firstArrival->vehicle_plate,
                        'dock' => $schedule->dock,
                        'label_part_status' => $session ? $session->label_part_status : 'PENDING',
                        'coa_msds_status' => $session ? $session->coa_msds_status : 'PENDING',
                        'packing_condition_status' => $session ? $session->packing_condition_status : 'PENDING',
                        'session_start' => $session && $session->session_start 
                            ? $session->session_start->format('Y-m-d H:i:s') 
                            : null,
                        'session_end' => $session && $session->session_end 
                            ? $session->session_end->format('Y-m-d H:i:s') 
                            : null,
                        'plan_delivery_date' => $date,
                        'total_qty_dn' => $totalQtyDn,
                        'actual_qty' => $actualQty,
                        'pic_name' => $picName,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'history' => $historyData,
            ]
        ]);
    }

    /**
     * Generate PDF for check sheet
     */
    public function downloadPdf(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);

        // Parse selected rows (row numbers) if provided
        $selectedRowsParam = $request->get('selected_rows');
        $selectedRowsLookup = null;
        if (!empty($selectedRowsParam)) {
            $rowNumbers = collect(explode(',', $selectedRowsParam))
                ->map(function ($value) {
                    return (int) trim($value);
                })
                ->filter(function ($value) {
                    return $value > 0;
                })
                ->unique()
                ->values()
                ->all();

            if (!empty($rowNumbers)) {
                $selectedRowsLookup = array_fill_keys($rowNumbers, true);
            }
        }

        // Get all schedules for the date
        $dayName = strtolower($carbonDate->format('l'));
        
        $schedules = \App\Models\ArrivalSchedule::where(function ($query) use ($date, $dayName) {
            $query->where(function ($q) use ($dayName) {
                $q->where('arrival_type', 'regular')
                  ->where('day_name', $dayName);
            })->orWhere(function ($q) use ($date) {
                $q->where('arrival_type', 'additional')
                  ->whereDate('schedule_date', $date);
            });
        })
        ->orderBy('arrival_time', 'asc')
        ->orderBy('bp_code', 'asc')
        ->get();

        $checkSheetData = [];
        $globalRowNumber = 1;
        $pdfRowNumber = 1;

        foreach ($schedules as $schedule) {
            // Only get arrivals that have DN
            $arrivals = ArrivalTransaction::where('schedule_id', $schedule->id)
                ->whereDate('plan_delivery_date', $date)
                ->whereNotNull('dn_number')
                ->where('dn_number', '!=', '')
                ->with(['scanSessions'])
                ->get();

            // Only process if there are arrivals with DN
            if ($arrivals->isNotEmpty()) {
                $arrivalsByDn = $arrivals->groupBy('dn_number');
                
                foreach ($arrivalsByDn as $dnNumber => $dnArrivals) {
                    $firstArrival = $dnArrivals->first();
                    $session = $firstArrival->scanSessions->first();
                    
                    // Get total qty from SCM
                    $totalQtyDn = \App\Models\External\ScmDnDetail::where('no_dn', $dnNumber)
                        ->sum('dn_qty');
                    
                    // Get actual qty
                    $actualQty = 0;
                    $picName = '-';
                    if ($session) {
                        $actualQty = \App\Models\ScannedItem::where('session_id', $session->id)
                            ->sum('scanned_quantity');
                        
                        if ($session->operator_id) {
                            $picName = $this->getPicName($session->operator_id) ?? '-';
                        }
                    }

                    // Format check status
                    $labelPart = $session && $session->label_part_status !== 'PENDING' 
                        ? ($session->label_part_status === 'OK' ? 'V' : 'X') 
                        : '-';
                    $coaMsds = $session && $session->coa_msds_status !== 'PENDING' 
                        ? ($session->coa_msds_status === 'OK' ? 'V' : 'X') 
                        : '-';
                    $packaging = $session && $session->packing_condition_status !== 'PENDING' 
                        ? ($session->packing_condition_status === 'OK' ? 'V' : 'X') 
                        : '-';

                    $shouldInclude = !$selectedRowsLookup || isset($selectedRowsLookup[$globalRowNumber]);

                    if ($shouldInclude) {
                        $checkSheetData[] = [
                            'no' => $pdfRowNumber++,
                            'supplier_name' => $this->getSupplierName($schedule->bp_code),
                            'planned_time' => $schedule->arrival_time 
                                ? Carbon::parse($schedule->arrival_time)->format('H:i') 
                                : '-',
                            'actual_time' => $firstArrival->warehouse_checkin_time 
                                ? Carbon::parse($firstArrival->warehouse_checkin_time)->format('H:i') 
                                : '-',
                            'dn_number' => $dnNumber,
                            'total_qty_dn' => $totalQtyDn > 0 ? number_format($totalQtyDn, 0, ',', '.') : '-',
                            'actual_qty' => $actualQty > 0 ? number_format($actualQty, 0, ',', '.') : '-',
                            'label_part' => $labelPart,
                            'coa_msds' => $coaMsds,
                            'packaging' => $packaging,
                            'pic_name' => $picName,
                        ];
                    }

                    $globalRowNumber++;
                }
            }
        }

        if (empty($checkSheetData)) {
            return response()->json([
                'success' => false,
                'message' => 'No rows found for the selected criteria.'
            ], 422);
        }

        // Generate PDF using dompdf
        $html = $this->generatePdfHtml($checkSheetData, $date);
        
        try {
            // Check if dompdf is available
            if (!class_exists('\Dompdf\Dompdf')) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF library (dompdf) is not installed. Please install it using: composer require dompdf/dompdf'
                ], 500);
            }
            
            $dompdf = new \Dompdf\Dompdf();
            
            // Set options for better PDF rendering
            $options = $dompdf->getOptions();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'Arial');
            $dompdf->setOptions($options);
            
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            
            // Get PDF output
            $pdfOutput = $dompdf->output();
            
            // Return PDF for inline display (preview) with proper headers
            return response($pdfOutput, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="check-sheet-' . $date . '.pdf"')
                ->header('Content-Length', strlen($pdfOutput))
                ->header('Cache-Control', 'private, max-age=0, must-revalidate')
                ->header('Pragma', 'public');
        } catch (\Exception $e) {
            // Log error and return HTML fallback
            try {
                \Log::error('PDF Generation Error: ' . $e->getMessage());
            } catch (\Exception $logError) {
                // Log might not be available
            }
            
            // Fallback: return HTML for browser print
            return response($html)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Disposition', 'inline; filename="check-sheet-' . $date . '.html"');
        }
    }

/**
 * Generate HTML for PDF with exact Excel layout
 */
protected function generatePdfHtml($data, $date)
{
    $formattedDate = Carbon::parse($date)->locale('id')->isoFormat('D MMMM YYYY');
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: Calibri, Arial, sans-serif;
                font-size: 11px;
                padding: 15px 15px;
                background: white;
            }
            .header-container {
                width: 100%;
                margin-bottom: 8px;
            }
            .header-row {
                display: table;
                width: 100%;
                margin-bottom: 3px;
            }
            .header-left {
                display: table-cell;
                font-size: 10px;
                width: 50%;
                vertical-align: top;
            }
            .header-right {
                display: table-cell;
                text-align: right;
                font-size: 9px;
                line-height: 1.3;
                width: 50%;
                vertical-align: top;
            }
            .title-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 3px;
            }
            .title-box {
                border: 0.5pt solid #000;
                padding: 6px 8px;
                font-size: 11px;
                font-weight: bold;
                letter-spacing: 0.3px;
                display: inline-block;
                width: auto;
            }
            .title-right {
                text-align: right;
                font-size: 9px;
                line-height: 1.3;
                flex: 0 0 auto;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9px;
                margin-top: 5px;
            }
            th, td {
                border: 0.5pt solid #000;
                padding: 5px 4px;
                text-align: center;
                vertical-align: middle;
            }
            th.no-border {
                border: none;
                background: white;
            }
            th {
                font-weight: bold;
                font-size: 7.5px;
                line-height: 1.2;
            }
            td {
                font-size: 8.5px;
            }
            .text-left {
                text-align: left;
                padding-left: 6px;
            }
            .rotate-text {
                writing-mode: vertical-rl;
                transform: rotate(-90deg);
                white-space: nowrap;
            }
            /* Remove borders for leader check column cells */
            td.leader-check-cell {
                border-left: 0.5pt solid #000;
                border-right: 0.5pt solid #000;
                border-top: none;
                border-bottom: none;
            }
            /* Only first row has top border */
            tr:first-child td.leader-check-cell {
                border-top: 0.5pt solid #000;
            }
            /* Only last row has bottom border */
            tr:last-child td.leader-check-cell {
                border-bottom: 0.5pt solid #000;
            }
            .footer-section {
                margin-top: 15px;
                font-size: 9px;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
            }
            .footer-left {
                flex: 0 0 auto;
            }
            .footer-right {
                text-align: right;
                flex: 0 0 auto;
            }
            .footer-section .note {
                font-style: italic;
                margin-bottom: 3px;
            }
            .footer-section .print-time {
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="header-container">
            <div class="header-row">
                <div class="header-left">
                    Lampiran-01R (0) 03082020
                </div>
                <div class="header-right">
                    PSQS : 03202000<br>
                    CONTROL NO : FM.PR.PC.WH-0002
                </div>
            </div>
            
            <div class="title-row">
                <div class="title-box">
                    CHECK SHEET PENERIMAAN BARANG GUDANG LOKAL SUPPLIER
                </div>
            </div>
        </div>
        
        <table>
    <thead>
        <tr>
            <th colspan="7" class="no-border" style="text-align: left; font-size: 9px; font-weight: normal; padding: 3px 0;"><span style="font-weight: normal;">HARI & TANGGAL :</span> <span style="font-weight: bold;">' . $formattedDate . '</span></th>
            <th colspan="5" style="width: 18%; font-size: 9px;">ITEM PENGECEKAN</th>
            <th colspan="2" class="no-border" style="text-align: right; font-size: 10px; font-weight: normal; line-height: 1.3;">Penulisan : ( V = OK ), ( X = NG )<br>( - = Tidak pakai )</th>
        </tr>
        <tr>
            <th rowspan="2" style="width: 3%;">NO</th>
            <th rowspan="1" style="width: 20%; border-bottom: none;">SUPPLIER</th>
            <th rowspan="1" style="width: 7%; border-bottom: none;">RENCANA JAM<br>DATANG</th>
            <th rowspan="1" style="width: 7%; border-bottom: none;">ACTUAL<br>JAM DATANG</th>
            <th rowspan="1" style="width: 12%; border-bottom: none;">NOMOR<br>SURAT JALAN</th>
            <th rowspan="1" style="width: 8%; border-bottom: none;">TOTAL<br>QTY SJ</th>
            <th rowspan="1" style="width: 6%; border-bottom: none;">ACT<br>QTY</th>
            <th rowspan="2" style="width: 3%;"><div class="rotate-text">LABEL<br>PART</div></th>
            <th rowspan="2" style="width: 3%;"><div class="rotate-text">COA/<br>MSDS</div></th>
            <th rowspan="2" style="width: 3%;"><div class="rotate-text">KEMASAN</div></th>
            <th rowspan="2" style="width: 3%;"></th>
            <th rowspan="2" style="width: 3%;"></th>
            <th rowspan="1" style="width: 10%; border-bottom: none;">PIC<br>PENERIMA</th>
            <th rowspan="1" style="width: 9%; border-bottom: none;">LEADER<br>CHECK<br>( 1XSehari )</th>
        </tr>
        <tr>
            <th style="width: 20%; border-top: none;"></th>
            <th style="width: 7%; border-top: none;"></th>
            <th style="width: 7%; border-top: none;"></th>
            <th style="width: 12%; border-top: none;"></th>
            <th style="width: 8%; border-top: none;"></th>
            <th style="width: 6%; border-top: none;"></th>
            <th style="width: 10%; border-top: none;"></th>
            <th style="width: 9%; border-top: none;"></th>
        </tr>
    </thead>
    <tbody>';

    $totalRows = count($data);
    $rowIndex = 0;
    foreach ($data as $row) {
        $rowIndex++;
        $isFirstRow = ($rowIndex === 1);
        $isLastRow = ($rowIndex === $totalRows);
        
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['no']) . '</td>';
        $html .= '<td class="text-left">' . htmlspecialchars($row['supplier_name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['planned_time']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['actual_time']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['dn_number']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['total_qty_dn']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['actual_qty']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['label_part']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['coa_msds']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['packaging']) . '</td>';
        $html .= '<td></td>';
        $html .= '<td></td>';
        $html .= '<td>' . htmlspecialchars($row['pic_name']) . '</td>';
        $html .= '<td class="leader-check-cell"></td>'; 
        $html .= '</tr>';
    }

    $html .= '
            </tbody>
        </table>
        
        <div class="footer-section">
            <div class="footer-right">
                <div class="note">Note : MSDS hanya untuk material baru yang belum terdaftar</div>
            </div>
            <div class="footer-left">
                <div class="print-time">Printed at: ' . Carbon::now()->format('d/m/Y H:i:s') . '</div>
            </div>
        </div>
    </body>
    </html>';

    return $html;
}

    /**
     * Helper methods
     */
    protected function getSupplierName($bpCode)
    {
        try {
            $supplier = \App\Models\External\ScmBusinessPartner::find($bpCode);
            if ($supplier && $supplier->bp_name) {
                return $supplier->bp_name;
            }
        } catch (\Exception $e) {
            // Fallback to settings
            $supplierData = \App\Models\Setting::getValue("supplier_{$bpCode}");
            if ($supplierData) {
                $data = json_decode($supplierData, true);
                return $data['name'] ?? $bpCode;
            }
        }
        return $bpCode;
    }

    protected function getPicName($picId)
    {
        if (!$picId) return null;
        
        try {
            $user = \App\Models\External\SphereUser::find($picId);
            return $user ? $user->name : null;
        } catch (\Exception $e) {
            return null;
        }
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
