<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Models\External\Visitor;
use App\Models\External\ScmDnDetail;
use App\Services\AuthService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get dashboard data for today
     */
    public function index(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);
        $date = $request->get('date', Carbon::today()->toDateString());

        // Get regular arrivals for the date
        $regularArrivals = $this->getRegularArrivals($date);
        
        // Get additional arrivals for the date
        $additionalArrivals = $this->getAdditionalArrivals($date);

        // Get summary statistics
        $summary = $this->getSummaryStatistics($date);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                        'level' => $user->role->level,
                    ],
                    'department' => $user->department ? [
                        'id' => $user->department->id,
                        'name' => $user->department->name,
                        'code' => $user->department->code,
                    ] : null,
                ] : null,
                'date' => $date,
                'regular_arrivals' => $regularArrivals,
                'additional_arrivals' => $additionalArrivals,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Get arrival schedule data (same as dashboard but for history/any date)
     */
    public function getScheduleData(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        // Get regular arrivals for the date
        $regularArrivals = $this->getRegularArrivals($date);
        
        // Get additional arrivals for the date
        $additionalArrivals = $this->getAdditionalArrivals($date);

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'regular_arrivals' => $regularArrivals,
                'additional_arrivals' => $additionalArrivals,
            ]
        ]);
    }

    /**
     * Get regular arrivals grouped by supplier
     * Master data is from arrival_schedule - only show suppliers scheduled for this day
     */
    protected function getRegularArrivals($date)
    {
        $carbonDate = Carbon::parse($date);
        $dayName = strtolower($carbonDate->format('l')); // monday, tuesday, etc.
        
        // Get all active schedules for this day (this is the master data)
        $activeSchedules = ArrivalSchedule::regular()
            ->where('day_name', $dayName)
            ->get();

        $groupedArrivals = [];

        foreach ($activeSchedules as $schedule) {
            // Get arrival transactions for this schedule's supplier on this date
            $arrivals = ArrivalTransaction::where('arrival_type', 'regular')
                ->where('bp_code', $schedule->bp_code)
                ->whereDate('plan_delivery_date', $date)
                ->with(['scanSessions.scannedItems'])
                ->get();

            // Link arrivals to this schedule if not already linked
            foreach ($arrivals as $arrival) {
                if (!$arrival->schedule_id) {
                    $arrival->schedule_id = $schedule->id;
                    $arrival->save();
                }
            }

            // Group arrivals by plan_delivery_date and plan_delivery_time AND bp_code
            $grouped = $arrivals->groupBy(function ($arrival) {
                $time = $arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00';
                return $arrival->bp_code . '_' . $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time;
            });

            foreach ($grouped as $key => $group) {
                $firstArrival = $group->first();
                
                // Calculate totals for all DNs in group
                // Get quantity_dn from dn_detail table (SCM)
                $totalQuantityDn = 0;
                $totalQuantityActual = 0;
                $allCheckSheetStatus = [
                    'label_part' => null,
                    'coa_msds' => null,
                    'packing' => null,
                ];
                
                foreach ($group as $arrival) {
                    // Get quantity_dn from SCM dn_detail
                    try {
                        $dnQuantity = ScmDnDetail::where('no_dn', $arrival->dn_number)
                            ->sum('dn_qty');
                        $totalQuantityDn += $dnQuantity;
                    } catch (\Exception $e) {
                        // Fallback to scanned items if SCM unavailable
                        $totalQuantityDn += $arrival->scannedItems->sum('expected_quantity');
                    }
                    
                    $totalQuantityActual += $arrival->scannedItems->sum('scanned_quantity');
                    
                    // Get check sheet status from scan session
                    $scanSession = $arrival->scanSessions->first();
                    if ($scanSession) {
                        if ($allCheckSheetStatus['label_part'] === null) {
                            $allCheckSheetStatus['label_part'] = $scanSession->label_part_status !== 'PENDING' ? $scanSession->label_part_status : null;
                        }
                        if ($allCheckSheetStatus['coa_msds'] === null) {
                            $allCheckSheetStatus['coa_msds'] = $scanSession->coa_msds_status !== 'PENDING' ? $scanSession->coa_msds_status : null;
                        }
                        if ($allCheckSheetStatus['packing'] === null) {
                            $allCheckSheetStatus['packing'] = $scanSession->packing_condition_status !== 'PENDING' ? $scanSession->packing_condition_status : null;
                        }
                    }
                }
                
                // Build DN list with details
                $dnList = [];
                foreach ($group as $arrival) {
                    // Get quantity_dn from SCM dn_detail
                    $dnQty = 0;
                    try {
                        $dnQty = ScmDnDetail::where('no_dn', $arrival->dn_number)->sum('dn_qty');
                    } catch (\Exception $e) {
                        $dnQty = $arrival->scannedItems->sum('expected_quantity');
                    }
                    
                    $scanSession = $arrival->scanSessions->first();
                    $dnList[] = [
                        'dn_number' => $arrival->dn_number,
                        'quantity_dn' => $dnQty,
                        'quantity_actual' => $arrival->scannedItems->sum('scanned_quantity'),
                        'scan_status' => $this->getScanStatusForArrival($arrival),
                    ];
                }
                
                $groupedArrivals[] = [
                    'group_key' => $key,
                    'supplier_name' => $this->getSupplierName($schedule->bp_code),
                    'bp_code' => $schedule->bp_code,
                    'schedule' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i') : null,
                    'schedule_time_for_sort' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i:s') : '00:00:00',
                    'dock' => $schedule->dock,
                    'vehicle_plate' => $firstArrival->vehicle_plate ?? '-',
                    'driver_name' => $firstArrival->driver_name ?? '-',
                    'security_time_in' => $firstArrival->security_checkin_time ? Carbon::parse($firstArrival->security_checkin_time)->format('H:i') : '-',
                    'security_time_out' => $firstArrival->security_checkout_time ? Carbon::parse($firstArrival->security_checkout_time)->format('H:i') : '-',
                    'security_duration' => $firstArrival->security_duration ? $this->formatDuration($firstArrival->security_duration) : '-',
                    'warehouse_time_in' => $firstArrival->warehouse_checkin_time ? Carbon::parse($firstArrival->warehouse_checkin_time)->format('H:i') : '-',
                    'warehouse_time_out' => $firstArrival->warehouse_checkout_time ? Carbon::parse($firstArrival->warehouse_checkout_time)->format('H:i') : '-',
                    'warehouse_duration' => $firstArrival->warehouse_duration ? $this->formatDuration($firstArrival->warehouse_duration) : '-',
                    'arrival_status' => $this->calculateArrivalStatus($firstArrival, $schedule),
                    'quantity_dn' => $totalQuantityDn,
                    'quantity_actual' => $totalQuantityActual,
                    'scan_status' => $this->getScanStatusForGroup($group),
                    'label_part' => $allCheckSheetStatus['label_part'] ?? null,
                    'coa_msds' => $allCheckSheetStatus['coa_msds'] ?? null,
                    'packing' => $allCheckSheetStatus['packing'] ?? null,
                    'pic' => $this->getPicName($firstArrival->pic_receiving) ?? '-',
                    'dn_count' => $group->count(),
                    'dn_numbers' => $group->pluck('dn_number')->toArray(),
                    'dn_list' => $dnList,
                ];
            }
        }

        // Sort by schedule time ascending
        usort($groupedArrivals, function($a, $b) {
            return strcmp($a['schedule_time_for_sort'] ?? '00:00:00', $b['schedule_time_for_sort'] ?? '00:00:00');
        });

        return $groupedArrivals;
    }

    /**
     * Get additional arrivals
     * Master data is from arrival_schedule - only show additional schedules for this specific date
     */
    protected function getAdditionalArrivals($date)
    {
        // Get all additional schedules for this specific date
        $activeSchedules = ArrivalSchedule::additional()
            ->whereDate('schedule_date', $date)
            ->get();

        $groupedArrivals = [];

        foreach ($activeSchedules as $schedule) {
            // Get arrival transactions for this schedule
            $arrivals = ArrivalTransaction::where('arrival_type', 'additional')
                ->where('bp_code', $schedule->bp_code)
                ->whereDate('plan_delivery_date', $date)
                ->where('schedule_id', $schedule->id)
                ->with(['scanSessions.scannedItems'])
                ->get();

            // Group arrivals by plan_delivery_date and plan_delivery_time AND bp_code
            $grouped = $arrivals->groupBy(function ($arrival) {
                $time = $arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00';
                return $arrival->bp_code . '_' . $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time;
            });

            foreach ($grouped as $key => $group) {
                $firstArrival = $group->first();
                
                // Calculate totals for all DNs in group
                // Get quantity_dn from dn_detail table (SCM)
                $totalQuantityDn = 0;
                $totalQuantityActual = 0;
                $allCheckSheetStatus = [
                    'label_part' => null,
                    'coa_msds' => null,
                    'packing' => null,
                ];
                
                foreach ($group as $arrival) {
                    // Get quantity_dn from SCM dn_detail
                    try {
                        $dnQuantity = ScmDnDetail::where('no_dn', $arrival->dn_number)
                            ->sum('dn_qty');
                        $totalQuantityDn += $dnQuantity;
                    } catch (\Exception $e) {
                        // Fallback to scanned items if SCM unavailable
                        $totalQuantityDn += $arrival->scannedItems->sum('expected_quantity');
                    }
                    
                    $totalQuantityActual += $arrival->scannedItems->sum('scanned_quantity');
                    
                    // Get check sheet status from scan session
                    $scanSession = $arrival->scanSessions->first();
                    if ($scanSession) {
                        if ($allCheckSheetStatus['label_part'] === null) {
                            $allCheckSheetStatus['label_part'] = $scanSession->label_part_status !== 'PENDING' ? $scanSession->label_part_status : null;
                        }
                        if ($allCheckSheetStatus['coa_msds'] === null) {
                            $allCheckSheetStatus['coa_msds'] = $scanSession->coa_msds_status !== 'PENDING' ? $scanSession->coa_msds_status : null;
                        }
                        if ($allCheckSheetStatus['packing'] === null) {
                            $allCheckSheetStatus['packing'] = $scanSession->packing_condition_status !== 'PENDING' ? $scanSession->packing_condition_status : null;
                        }
                    }
                }
                
                // Build DN list with details
                $dnList = [];
                foreach ($group as $arrival) {
                    // Get quantity_dn from SCM dn_detail
                    $dnQty = 0;
                    try {
                        $dnQty = ScmDnDetail::where('no_dn', $arrival->dn_number)->sum('dn_qty');
                    } catch (\Exception $e) {
                        $dnQty = $arrival->scannedItems->sum('expected_quantity');
                    }
                    
                    $scanSession = $arrival->scanSessions->first();
                    $dnList[] = [
                        'dn_number' => $arrival->dn_number,
                        'quantity_dn' => $dnQty,
                        'quantity_actual' => $arrival->scannedItems->sum('scanned_quantity'),
                        'scan_status' => $this->getScanStatusForArrival($arrival),
                    ];
                }
                
                $groupedArrivals[] = [
                    'group_key' => $key,
                    'supplier_name' => $this->getSupplierName($schedule->bp_code),
                    'bp_code' => $schedule->bp_code,
                    'schedule' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i') : null,
                    'schedule_time_for_sort' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i:s') : '00:00:00',
                    'dock' => $schedule->dock,
                    'vehicle_plate' => $firstArrival->vehicle_plate ?? '-',
                    'driver_name' => $firstArrival->driver_name ?? '-',
                    'security_time_in' => $firstArrival->security_checkin_time ? Carbon::parse($firstArrival->security_checkin_time)->format('H:i') : '-',
                    'security_time_out' => $firstArrival->security_checkout_time ? Carbon::parse($firstArrival->security_checkout_time)->format('H:i') : '-',
                    'security_duration' => $firstArrival->security_duration ? $this->formatDuration($firstArrival->security_duration) : '-',
                    'warehouse_time_in' => $firstArrival->warehouse_checkin_time ? Carbon::parse($firstArrival->warehouse_checkin_time)->format('H:i') : '-',
                    'warehouse_time_out' => $firstArrival->warehouse_checkout_time ? Carbon::parse($firstArrival->warehouse_checkout_time)->format('H:i') : '-',
                    'warehouse_duration' => $firstArrival->warehouse_duration ? $this->formatDuration($firstArrival->warehouse_duration) : '-',
                    'arrival_status' => $this->calculateArrivalStatus($firstArrival, $schedule),
                    'quantity_dn' => $totalQuantityDn,
                    'quantity_actual' => $totalQuantityActual,
                    'scan_status' => $this->getScanStatusForGroup($group),
                    'label_part' => $allCheckSheetStatus['label_part'] ?? null,
                    'coa_msds' => $allCheckSheetStatus['coa_msds'] ?? null,
                    'packing' => $allCheckSheetStatus['packing'] ?? null,
                    'pic' => $this->getPicName($firstArrival->pic_receiving) ?? '-',
                    'dn_count' => $group->count(),
                    'dn_numbers' => $group->pluck('dn_number')->toArray(),
                    'dn_list' => $dnList,
                ];
            }
        }

        // Sort by schedule time ascending
        usort($groupedArrivals, function($a, $b) {
            return strcmp($a['schedule_time_for_sort'] ?? '00:00:00', $b['schedule_time_for_sort'] ?? '00:00:00');
        });

        return $groupedArrivals;
    }

    /**
     * Get summary statistics
     */
    protected function getSummaryStatistics($date)
    {
        $totalArrivals = ArrivalTransaction::forDate($date)->count();
        $onTimeArrivals = ArrivalTransaction::forDate($date)->where('status', 'on_time')->count();
        $delayArrivals = ArrivalTransaction::forDate($date)->where('status', 'delay')->count();
        $advanceArrivals = ArrivalTransaction::forDate($date)->where('status', 'advance')->count();
        $pendingArrivals = ArrivalTransaction::forDate($date)->where('status', 'pending')->count();

        return [
            'total_arrivals' => $totalArrivals,
            'on_time' => $onTimeArrivals,
            'delay' => $delayArrivals,
            'advance' => $advanceArrivals,
            'pending' => $pendingArrivals,
            'on_time_percentage' => $totalArrivals > 0 ? round(($onTimeArrivals / $totalArrivals) * 100, 2) : 0,
            'delay_percentage' => $totalArrivals > 0 ? round(($delayArrivals / $totalArrivals) * 100, 2) : 0,
        ];
    }

    /**
     * Get DN details for a specific group
     */
    public function getDnDetails(Request $request)
    {
        $groupKey = $request->get('group_key');
        $date = $request->get('date', Carbon::today()->toDateString());

        if (!$groupKey) {
            return response()->json([
                'success' => false,
                'message' => 'Group key is required'
            ], 400);
        }

        // Parse group key: bp_code_date_time
        $parts = explode('_', $groupKey);
        if (count($parts) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid group key format'
            ], 400);
        }
        $bpCode = $parts[0];
        $deliveryDate = $parts[1];
        $deliveryTime = implode('_', array_slice($parts, 2)); // Handle time format H:i:s

        $arrivals = ArrivalTransaction::forDate($deliveryDate)
            ->where('bp_code', $bpCode)
            ->where('plan_delivery_time', $deliveryTime)
            ->with(['scanSessions.scannedItems'])
            ->get();

        $dnDetails = [];

        foreach ($arrivals as $arrival) {
            $scanSession = $arrival->scanSessions->first();
            $scannedItems = $scanSession ? $scanSession->scannedItems : collect();

            // Get quantity_dn from SCM
            $dnQty = 0;
            try {
                $dnQty = ScmDnDetail::where('no_dn', $arrival->dn_number)->sum('dn_qty');
            } catch (\Exception $e) {
                $dnQty = $scannedItems->sum('expected_quantity');
            }

            $dnDetails[] = [
                'dn_number' => $arrival->dn_number,
                'scan_status' => $this->getScanStatusForArrival($arrival),
                'quantity_dn' => $dnQty,
                'quantity_actual' => $scannedItems->sum('scanned_quantity'),
                'items' => $scannedItems->map(function ($item) {
                    return [
                        'part_no' => $item->part_no,
                        'expected_quantity' => $item->expected_quantity,
                        'scanned_quantity' => $item->scanned_quantity,
                        'match_status' => $item->match_status,
                    ];
                })->toArray(),
            ];
        }

        $totalDn = count($dnDetails);
        $totalQuantityDn = collect($dnDetails)->sum('quantity_dn');
        $totalQuantityActual = collect($dnDetails)->sum('quantity_actual');

        return response()->json([
            'success' => true,
            'data' => [
                'dn_details' => $dnDetails,
                'summary' => [
                    'total_dn' => $totalDn,
                    'total_quantity_dn' => $totalQuantityDn,
                    'total_quantity_actual' => $totalQuantityActual,
                ]
            ]
        ]);
    }

    /**
     * Helper methods
     */
    protected function getSupplierName($bpCode)
    {
        // Get supplier name directly from SCM database
        try {
            $supplier = \App\Models\External\ScmBusinessPartner::find($bpCode);
            if ($supplier && $supplier->bp_name) {
                return $supplier->bp_name;
            }
        } catch (\Exception $e) {
            // Fallback to settings if SCM connection fails
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
        
        // Get PIC name from Sphere database
        $user = \App\Models\External\SphereUser::find($picId);
        return $user ? $user->name : null;
    }

    protected function calculateArrivalStatus($arrival, $schedule = null)
    {
        $schedule = $schedule ?? $arrival->schedule;
        
        if (!$schedule || !$schedule->arrival_time) {
            return '-';
        }

        $actualTime = $arrival->security_checkin_time ?? $arrival->warehouse_checkin_time;
        if (!$actualTime) {
            return 'pending';
        }

        $scheduledTime = Carbon::parse($arrival->plan_delivery_date . ' ' . $schedule->arrival_time);
        $actualTime = Carbon::parse($actualTime);
        $diffMinutes = $actualTime->diffInMinutes($scheduledTime, false);

        if ($diffMinutes <= -5) return 'Advance';
        if ($diffMinutes > 5) return 'Delay';
        return 'Ontime';
    }

    protected function getScanStatusForArrival($arrival)
    {
        $sessions = $arrival->scanSessions;
        if ($sessions->isEmpty()) {
            return 'Pending';
        }
        
        $completedSessions = $sessions->where('status', 'completed')->count();
        $totalSessions = $sessions->count();
        
        if ($completedSessions === $totalSessions && $totalSessions > 0) {
            return 'Completed';
        } elseif ($completedSessions > 0) {
            return 'In Progress';
        }
        
        return 'Pending';
    }

    protected function getScanStatusForGroup($group)
    {
        $statuses = $group->map(function ($arrival) {
            return $this->getScanStatusForArrival($arrival);
        })->unique()->values();
        
        if ($statuses->contains('Completed') && $statuses->count() === 1) {
            return 'Completed';
        } elseif ($statuses->contains('In Progress') || $statuses->contains('Completed')) {
            return 'In Progress';
        }
        
        return 'Pending';
    }

    protected function formatDuration($minutes)
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

    protected function formatDnNumbers($dnNumbers)
    {
        $count = count($dnNumbers);
        return "{$count} DN";
    }
}
