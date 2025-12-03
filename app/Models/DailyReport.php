<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class DailyReport extends Model
{
    use HasFactory;

    protected $table = 'daily_reports';

    protected $fillable = [
        'report_date',
        'total_suppliers',
        'total_arrivals',
        'total_on_time',
        'total_delay',
        'total_advance',
        'file_path',
        'generated_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'generated_at' => 'datetime',
    ];

    /**
     * Scope for specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('report_date', $date);
    }

    /**
     * Scope for date range
     */
    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('report_date', [$startDate, $endDate]);
    }

    /**
     * Calculate on-time percentage
     */
    public function getOnTimePercentageAttribute()
    {
        if ($this->total_arrivals === 0) {
            return 0;
        }
        return round(($this->total_on_time / $this->total_arrivals) * 100, 2);
    }

    /**
     * Calculate delay percentage
     */
    public function getDelayPercentageAttribute()
    {
        if ($this->total_arrivals === 0) {
            return 0;
        }
        return round(($this->total_delay / $this->total_arrivals) * 100, 2);
    }

    /**
     * Generate report for specific date
     */
    public static function generateForDate($date)
    {
        $carbonDate = Carbon::parse($date);
        $dayName = strtolower($carbonDate->format('l')); // monday, tuesday, etc.

        // Calculate total suppliers (from schedule - same logic as DashboardController)
        $regularSchedules = \App\Models\ArrivalSchedule::regular()
            ->where('day_name', $dayName)
            ->get();

        $additionalSchedules = \App\Models\ArrivalSchedule::additional()
            ->whereDate('schedule_date', $date)
            ->get();

        // Get unique suppliers from schedules
        $supplierCodes = collect();
        foreach ($regularSchedules as $schedule) {
            $supplierCodes->push($schedule->bp_code);
        }
        foreach ($additionalSchedules as $schedule) {
            $supplierCodes->push($schedule->bp_code);
        }
        $totalSuppliers = $supplierCodes->unique()->count();

        // Calculate total arrivals (suppliers that actually came)
        // Must have: security_checkin_time, security_checkout_time, warehouse_checkin_time, warehouse_checkout_time
        $arrivalsWithCompleteData = ArrivalTransaction::forDate($date)
            ->whereNotNull('security_checkin_time')
            ->whereNotNull('security_checkout_time')
            ->whereNotNull('warehouse_checkin_time')
            ->whereNotNull('warehouse_checkout_time')
            ->get();

        // Get unique suppliers from arrivals that have complete data
        $arrivedSupplierCodes = $arrivalsWithCompleteData
            ->pluck('bp_code')
            ->unique();
        $totalArrivals = $arrivedSupplierCodes->count();

        // Calculate on_time, delay, advance from arrival status
        // For each supplier that arrived, get the worst status from their arrivals
        $supplierStatuses = [];
        foreach ($arrivalsWithCompleteData as $arrival) {
            $bpCode = $arrival->bp_code;
            if (!isset($supplierStatuses[$bpCode])) {
                $supplierStatuses[$bpCode] = [];
            }
            $supplierStatuses[$bpCode][] = $arrival->status;
        }

        $totalOnTime = 0;
        $totalDelay = 0;
        $totalAdvance = 0;

        // Priority: advance > delay > on_time > pending
        foreach ($supplierStatuses as $bpCode => $statuses) {
            if (in_array('advance', $statuses)) {
                $totalAdvance++;
            } elseif (in_array('delay', $statuses)) {
                $totalDelay++;
            } elseif (in_array('on_time', $statuses)) {
                $totalOnTime++;
            }
        }

        // Generate PDF
        $filePath = self::generatePdf($date, [
            'total_suppliers' => $totalSuppliers,
            'total_arrivals' => $totalArrivals,
            'total_on_time' => $totalOnTime,
            'total_delay' => $totalDelay,
            'total_advance' => $totalAdvance,
        ]);

        return self::create([
            'report_date' => $date,
            'total_suppliers' => $totalSuppliers,
            'total_arrivals' => $totalArrivals,
            'total_on_time' => $totalOnTime,
            'total_delay' => $totalDelay,
            'total_advance' => $totalAdvance,
            'file_path' => $filePath,
            'generated_at' => now(),
        ]);
    }

    /**
     * Generate PDF for daily report
     */
    protected static function generatePdf($date, $stats)
    {
        try {
            // Check if dompdf is available
            if (!class_exists('\Dompdf\Dompdf')) {
                \Illuminate\Support\Facades\Log::warning('PDF library (dompdf) is not installed. Skipping PDF generation.');
                return null;
            }

            // Create storage directory if it doesn't exist
            $storagePath = storage_path('app/daily-reports');
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            // Generate HTML
            $html = self::generatePdfHtml($date, $stats);

            // Generate PDF
            $dompdf = new \Dompdf\Dompdf();
            $options = $dompdf->getOptions();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'Arial');
            $dompdf->setOptions($options);

            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            // Save PDF
            $filename = 'daily-report-' . $date . '.pdf';
            $filePath = $storagePath . '/' . $filename;
            file_put_contents($filePath, $dompdf->output());

            return 'daily-reports/' . $filename;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to generate PDF for daily report: ' . $e->getMessage(), [
                'date' => $date,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Generate HTML for PDF with style similar to CheckSheetController
     */
    protected static function generatePdfHtml($date, $stats)
    {
        $formattedDate = Carbon::parse($date)->locale('id')->isoFormat('D MMMM YYYY');
        $carbonDate = Carbon::parse($date);
        $dayName = strtolower($carbonDate->format('l'));

        // Get schedule data
        $regularSchedules = \App\Models\ArrivalSchedule::regular()
            ->where('day_name', $dayName)
            ->orderBy('arrival_time')
            ->get();

        $additionalSchedules = \App\Models\ArrivalSchedule::additional()
            ->whereDate('schedule_date', $date)
            ->orderBy('arrival_time')
            ->get();

        // Get arrivals data
        $allSchedules = $regularSchedules->merge($additionalSchedules);
        $reportData = [];

        foreach ($allSchedules as $schedule) {
            $arrivals = ArrivalTransaction::where('bp_code', $schedule->bp_code)
                ->whereDate('plan_delivery_date', $date)
                ->where('schedule_id', $schedule->id)
                ->with('scanSessions.scannedItems')
                ->get();

            $arrival = $arrivals->first(function ($a) {
                return $a->security_checkin_time || $a->warehouse_checkin_time;
            }) ?? $arrivals->first();

            $supplierName = self::getSupplierName($schedule->bp_code);

            $vehiclePlate = $arrival && $arrival->vehicle_plate ? $arrival->vehicle_plate : '-';
            $securityIn = $arrival && $arrival->security_checkin_time
                ? Carbon::parse($arrival->security_checkin_time)->format('H:i')
                : '-';
            $securityOut = $arrival && $arrival->security_checkout_time
                ? Carbon::parse($arrival->security_checkout_time)->format('H:i')
                : '-';
            $securityDuration = $arrival && $arrival->security_duration
                ? self::formatDuration($arrival->security_duration)
                : '-';

            $warehouseIn = $arrival && $arrival->warehouse_checkin_time
                ? Carbon::parse($arrival->warehouse_checkin_time)->format('H:i')
                : '-';
            $warehouseOut = $arrival && $arrival->warehouse_checkout_time
                ? Carbon::parse($arrival->warehouse_checkout_time)->format('H:i')
                : '-';
            $warehouseDuration = $arrival && $arrival->warehouse_duration
                ? self::formatDuration($arrival->warehouse_duration)
                : '-';

            $statuses = $arrivals->pluck('status')->toArray();
            $worstStatus = 'pending';
            if (in_array('advance', $statuses, true)) {
                $worstStatus = 'advance';
            } elseif (in_array('delay', $statuses, true)) {
                $worstStatus = 'delay';
            } elseif (in_array('on_time', $statuses, true)) {
                $worstStatus = 'on_time';
            }

            $picName = '-';
            if ($arrival && $arrival->pic_receiving) {
                try {
                    $user = \App\Models\External\SphereUser::find($arrival->pic_receiving);
                    if ($user) {
                        $picName = $user->name;
                    }
                } catch (\Exception $e) {
                    // ignore
                }
            }

            $scanSession = $arrival && $arrival->scanSessions ? $arrival->scanSessions->first() : null;
            $labelPart = $scanSession && $scanSession->label_part_status !== 'PENDING'
                ? ($scanSession->label_part_status === 'OK' ? 'V' : 'X')
                : '-';
            $coaMsds = $scanSession && $scanSession->coa_msds_status !== 'PENDING'
                ? ($scanSession->coa_msds_status === 'OK' ? 'V' : 'X')
                : '-';
            $packaging = $scanSession && $scanSession->packing_condition_status !== 'PENDING'
                ? ($scanSession->packing_condition_status === 'OK' ? 'V' : 'X')
                : '-';

            $dnGroups = $arrivals->groupBy(function ($arrival) {
                return $arrival->dn_number ?: uniqid('nodn_');
            });

            if ($dnGroups->isEmpty()) {
                $reportData[] = [
                    'no' => count($reportData) + 1,
                    'supplier_name' => $supplierName,
                    'planned_time' => $schedule->arrival_time
                        ? Carbon::parse($schedule->arrival_time)->format('H:i')
                        : '-',
                    'dock' => $schedule->dock ?? '-',
                    'vehicle_plate' => $vehiclePlate,
                    'security_in' => $securityIn,
                    'security_out' => $securityOut,
                    'security_duration' => $securityDuration,
                    'warehouse_in' => $warehouseIn,
                    'warehouse_out' => $warehouseOut,
                    'warehouse_duration' => $warehouseDuration,
                    'dn_number' => '-',
                    'qty_sj' => '-',
                    'act_qty' => '-',
                    'label_part' => $labelPart,
                    'coa_msds' => $coaMsds,
                    'packaging' => $packaging,
                    'pic_name' => $picName,
                    'status' => $worstStatus,
                ];
            } else {
                foreach ($dnGroups as $dnNumber => $dnArrivals) {
                    $displayDn = $dnArrivals->first()->dn_number ?? '-';

                    $dnQty = 0;
                    $dnActQty = 0;
                    try {
                        if ($displayDn !== '-') {
                            $dnQty = \App\Models\External\ScmDnDetail::where('no_dn', $displayDn)->sum('dn_qty');
                        }
                    } catch (\Exception $e) {
                        // ignore
                    }

                    foreach ($dnArrivals as $dnArrival) {
                        $scanSession = $dnArrival->scanSessions->first();
                        if ($scanSession) {
                            $dnActQty += $scanSession->scannedItems->sum('scanned_quantity');
                        }
                    }

                    $reportData[] = [
                        'no' => count($reportData) + 1,
                        'supplier_name' => $supplierName,
                        'planned_time' => $schedule->arrival_time
                            ? Carbon::parse($schedule->arrival_time)->format('H:i')
                            : '-',
                        'dock' => $schedule->dock ?? '-',
                        'vehicle_plate' => $vehiclePlate,
                        'security_in' => $securityIn,
                        'security_out' => $securityOut,
                        'security_duration' => $securityDuration,
                        'warehouse_in' => $warehouseIn,
                        'warehouse_out' => $warehouseOut,
                        'warehouse_duration' => $warehouseDuration,
                        'dn_number' => $displayDn,
                        'qty_sj' => $dnQty > 0 ? number_format($dnQty, 0, ',', '.') : '-',
                        'act_qty' => $dnActQty > 0 ? number_format($dnActQty, 0, ',', '.') : '-',
                        'label_part' => $labelPart,
                        'coa_msds' => $coaMsds,
                        'packaging' => $packaging,
                        'pic_name' => $picName,
                        'status' => $worstStatus,
                    ];
                }
            }
        }

        // Generate HTML with improved column widths
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
                font-size: 10px;
                padding: 12px;
                background: white;
            }
            .header-container {
                width: 100%;
                margin-bottom: 6px;
            }
            .header-row {
                display: table;
                width: 100%;
                margin-bottom: 2px;
            }
            .header-left {
                display: table-cell;
                font-size: 9px;
                width: 50%;
                vertical-align: top;
            }
            .title-row {
                margin-bottom: 3px;
            }
            .title-box {
                border: 0.5pt solid #000;
                padding: 5px 7px;
                font-size: 10px;
                font-weight: bold;
                letter-spacing: 0.2px;
                display: inline-block;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8px;
                margin-top: 4px;
            }
            th, td {
                border: 0.5pt solid #000;
                padding: 3px 2px;
                text-align: center;
                vertical-align: middle;
            }
            th {
                font-weight: bold;
                font-size: 6.5px;
                line-height: 1.15;
            }
            td {
                font-size: 7.5px;
            }
            .text-left {
                text-align: left;
                padding-left: 4px;
            }
            th.no-border {
                border: none;
                background: white;
            }
            .footer-section {
                margin-top: 10px;
                font-size: 8px;
            }
            .summary-table {
                width: 20%;
                border-collapse: collapse;
                margin-top: 8px;
                font-size: 7.5px;
            }
            .summary-table td {
                border: 0.5pt solid #000;
                padding: 4px 6px;
            }
            .summary-table .summary-label {
                font-weight: bold;
                text-align: left;
                width: 70%;
            }
            .summary-table .summary-value {
                text-align: center;
                width: 30%;
            }
        </style>
    </head>
    <body>
        <div class="header-container">
            <div class="header-row">
                <div class="header-left">
                    DR' . Carbon::parse($date)->format('dmy') . '
                </div>
            </div>
            
            <div class="title-row">
                <div class="title-box">
                    DAILY REPORT PENERIMAAN BARANG GUDANG LOKAL SUPPLIER
                </div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th colspan="18" class="no-border" style="text-align: left; font-size: 8px; font-weight: normal; padding: 2px 0;">
                        <span style="font-weight: normal;">HARI &amp; TANGGAL :</span>
                        <span style="font-weight: bold;">' . $formattedDate . '</span>
                    </th>
                </tr>
                <tr>
                    <th rowspan="2" style="width: 2.5%;">NO</th>
                    <th rowspan="2" style="width: 16%;">SUPPLIER</th>
                    <th rowspan="2" style="width: 5.5%;">RENCANA<br>JAM<br>DATANG</th>
                    <th rowspan="2" style="width: 4%;">DOCK</th>
                    <th rowspan="1" style="width: 7%;">NOMOR</th>
                    <th colspan="2" style="width: 10%;">SECURITY</th>
                    <th rowspan="2" style="width: 5%;">DUR<br>(SEC)</th>
                    <th colspan="2" style="width: 10%;">WAREHOUSE</th>
                    <th rowspan="2" style="width: 5%;">DUR<br>(WH)</th>
                    <th rowspan="2" style="width: 9%;">NOMOR<br>SURAT JALAN</th>
                    <th rowspan="2" style="width: 5.5%;">QTY<br>SJ</th>
                    <th rowspan="2" style="width: 5.5%;">ACT<br>QTY</th>
                    <th colspan="3" style="width: 9%;">ITEM PENGECEKAN</th>
                    <th rowspan="1" style="width: 10%;">PIC</th>
                </tr>
                <tr>
                    <th>KENDARAAN</th>
                    <th style="width: 5%;">IN</th>
                    <th style="width: 5%;">OUT</th>
                    <th style="width: 5%;">IN</th>
                    <th style="width: 5%;">OUT</th>
                    <th style="width: 3%;">LABEL<br>PART</th>
                    <th style="width: 3%;">COA/<br>MSDS</th>
                    <th style="width: 3%;">KEMASAN</th>
                    <th>PENERIMA</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($reportData as $row) {
            $dnNumber = $row['dn_number'] ?? '-';

            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['no']) . '</td>';
            $html .= '<td class="text-left">' . htmlspecialchars($row['supplier_name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['planned_time']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['dock']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['vehicle_plate']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['security_in']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['security_out']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['security_duration']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['warehouse_in']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['warehouse_out']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['warehouse_duration']) . '</td>';
            $html .= '<td>' . htmlspecialchars($dnNumber) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['qty_sj']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['act_qty']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['label_part']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['coa_msds']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['packaging']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['pic_name']) . '</td>';
            $html .= '</tr>';
        }

        $html .= '
            </tbody>
        </table>
        
        <table class="summary-table">
            <tr>
                <td class="summary-label">TOTAL SUPPLIER</td>
                <td class="summary-value">' . $stats['total_suppliers'] . '</td>
            </tr>
            <tr>
                <td class="summary-label">TOTAL KEDATANGAN</td>
                <td class="summary-value">' . $stats['total_arrivals'] . '</td>
            </tr>
            <tr>
                <td class="summary-label">TOTAL ON TIME</td>
                <td class="summary-value">' . $stats['total_on_time'] . '</td>
            </tr>
            <tr>
                <td class="summary-label">TOTAL DELAY</td>
                <td class="summary-value">' . $stats['total_delay'] . '</td>
            </tr>
            <tr>
                <td class="summary-label">TOTAL ADVANCE</td>
                <td class="summary-value">' . $stats['total_advance'] . '</td>
            </tr>
        </table>
        
        <div class="footer-section">
            <div>Generated at: ' . Carbon::now()->format('d/m/Y H:i:s') . '</div>
        </div>
    </body>
    </html>';

        return $html;
    }

    /**
     * Get supplier name
     */
    protected static function getSupplierName($bpCode)
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

    /**
     * Format duration
     */
    protected static function formatDuration($minutes)
    {
        if (!$minutes || $minutes == 0) return '-';

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$mins}m";
        }
    }
}
