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
            ->orderBy('arrival_time')
            ->get();

        // First, ensure all regular arrivals for this supplier & date
        // are linked to the most appropriate schedule using a \"nearest schedule\" strategy.
        $this->assignRegularArrivalsToNearestSchedules($date, $activeSchedules);

        $groupedArrivals = [];

        foreach ($activeSchedules as $schedule) {
            // After assignment above, arrivals for this supplier & date
            // that belong to this schedule already have schedule_id = $schedule->id
            $arrivals = ArrivalTransaction::where('arrival_type', 'regular')
                ->where('bp_code', $schedule->bp_code)
                ->whereDate('plan_delivery_date', $date)
                ->where('schedule_id', $schedule->id)
                ->with(['scanSessions.scannedItems'])
                ->get();

            // Separate confirmed (has driver/vehicle) and unconfirmed arrivals
            $confirmedArrivals = $arrivals->filter(function ($arrival) {
                return !empty($arrival->driver_name) && !empty($arrival->vehicle_plate);
            });
            
            $unconfirmedArrivals = $arrivals->filter(function ($arrival) {
                return empty($arrival->driver_name) || empty($arrival->vehicle_plate);
            });

            // Group confirmed arrivals by bp_code, plan_delivery_date, plan_delivery_time, driver_name, and vehicle_plate
            $confirmedGrouped = $confirmedArrivals->groupBy(function ($arrival) {
                $time = $arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00';
                $driver = $arrival->driver_name ?? 'null';
                $plate = $arrival->vehicle_plate ?? 'null';
                return $arrival->bp_code . '_' . $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time . '_driver_' . md5($driver) . '_plate_' . md5($plate);
            });

            // For unconfirmed arrivals, try to attach them to matching confirmed groups
            // If no matching confirmed group exists, create separate groups for unconfirmed
            $unconfirmedGrouped = collect();
            foreach ($unconfirmedArrivals as $unconfirmed) {
                $time = $unconfirmed->plan_delivery_time ? Carbon::parse($unconfirmed->plan_delivery_time)->format('H:i:s') : '00:00:00';
                $baseKey = $unconfirmed->bp_code . '_' . $unconfirmed->plan_delivery_date->format('Y-m-d') . '_' . $time;
                
                // Find matching confirmed group (same bp_code, date, time)
                $matchedGroup = null;
                $matchedKey = null;
                foreach ($confirmedGrouped as $key => $group) {
                    if (strpos($key, $baseKey) === 0) {
                        $matchedGroup = $group;
                        $matchedKey = $key;
                        break;
                    }
                }
                
                if ($matchedGroup) {
                    // Attach unconfirmed to existing confirmed group
                    $matchedGroup->push($unconfirmed);
                } else {
                    // Create separate group for unconfirmed
                    $unconfirmedKey = $baseKey . '_driver_null_plate_null';
                    if (!$unconfirmedGrouped->has($unconfirmedKey)) {
                        $unconfirmedGrouped->put($unconfirmedKey, collect([$unconfirmed]));
                    } else {
                        $unconfirmedGrouped->get($unconfirmedKey)->push($unconfirmed);
                    }
                }
            }

            // Merge confirmed and unconfirmed groups
            // Convert both to arrays and merge, then convert back to collection
            $grouped = collect(array_merge($confirmedGrouped->all(), $unconfirmedGrouped->all()));

            foreach ($grouped as $key => $group) {
                // Find confirmed arrival (has driver and vehicle) - ONLY use this for display fields
                // If no confirmed arrival exists, we should NOT show driver/vehicle from unconfirmed arrivals
                // This prevents showing wrong data from other dates or unconfirmed entries
                $confirmedArrival = $group->first(function ($arrival) {
                    return !empty($arrival->driver_name) && !empty($arrival->vehicle_plate);
                });
                
                // Use confirmed arrival for display, or null if none exists
                // This ensures we don't show wrong driver/vehicle data from unconfirmed arrivals
                $displayArrival = $confirmedArrival;
                
                // For other calculations, we still need a reference arrival
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
                $deliveredCount = 0;
                
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
                    if (!empty($arrival->driver_name) && !empty($arrival->vehicle_plate)) {
                        $deliveredCount++;
                    }
                    
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
                        'is_confirmed' => !empty($arrival->driver_name) && !empty($arrival->vehicle_plate),
                        'status' => $arrival->status,
                    ];
                }
                
                $groupedArrivals[] = [
                    'group_key' => $key,
                    'supplier_name' => $this->getSupplierName($schedule->bp_code),
                    'bp_code' => $schedule->bp_code,
                    'schedule' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i') : null,
                    'schedule_time_for_sort' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i:s') : '00:00:00',
                    'dock' => $schedule->dock,
                    'vehicle_plate' => $displayArrival ? ($displayArrival->vehicle_plate ?? '-') : '-',
                    'driver_name' => $displayArrival ? ($displayArrival->driver_name ?? '-') : '-',
                    'security_time_in' => $displayArrival && $displayArrival->security_checkin_time ? Carbon::parse($displayArrival->security_checkin_time)->format('H:i') : '-',
                    'security_time_out' => $displayArrival && $displayArrival->security_checkout_time ? Carbon::parse($displayArrival->security_checkout_time)->format('H:i') : '-',
                    'security_duration' => $displayArrival && $displayArrival->security_duration ? $this->formatDuration($displayArrival->security_duration) : '-',
                    'warehouse_time_in' => $displayArrival && $displayArrival->warehouse_checkin_time ? Carbon::parse($displayArrival->warehouse_checkin_time)->format('H:i') : '-',
                    'warehouse_time_out' => $displayArrival && $displayArrival->warehouse_checkout_time ? Carbon::parse($displayArrival->warehouse_checkout_time)->format('H:i') : '-',
                    'warehouse_duration' => $displayArrival && $displayArrival->warehouse_duration ? $this->formatDuration($displayArrival->warehouse_duration) : '-',
                    // Use status from arrival_transactions table directly, not calculated
                    // Status should be set by backend logic elsewhere, not here
                    'arrival_status' => $displayArrival ? ($displayArrival->status ?? 'pending') : 'pending',
                    'quantity_dn' => $totalQuantityDn,
                    'quantity_actual' => $totalQuantityActual,
                    'scan_status' => $this->getScanStatusForGroup($group),
                    'dn_status' => $this->formatDeliveryCompliance($this->getWorstDeliveryCompliance($group)),
                    'dn_status_raw' => $this->getWorstDeliveryCompliance($group),
                    'label_part' => $allCheckSheetStatus['label_part'] ?? null,
                    'coa_msds' => $allCheckSheetStatus['coa_msds'] ?? null,
                    'packing' => $allCheckSheetStatus['packing'] ?? null,
                    'pic' => $displayArrival ? ($this->getPicName($displayArrival->pic_receiving) ?? '-') : '-',
                    'dn_count' => $group->count(),
                    'dn_delivered_count' => $deliveredCount,
                    'delivered_info' => "{$deliveredCount} of " . $group->count() . " delivered",
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
     * Assign regular arrivals to the nearest schedules for a given date and set of active schedules.
     *
     * For each supplier (bp_code) on that day:
     *  - Sort schedules by arrival_time ascending
     *  - Sort arrivals by plan_delivery_time ascending
     *  - Walk arrivals in time order and, for each one, pick the nearest schedule
     *    among the schedules at or after the last used index (to preserve chronological order).
     *  - Set schedule_id on arrivals that are not yet linked or linked to a different day's schedule.
     */
    protected function assignRegularArrivalsToNearestSchedules($date, $activeSchedules): void
    {
        if ($activeSchedules->isEmpty()) {
            return;
        }

        // Group schedules by supplier
        $schedulesBySupplier = $activeSchedules->groupBy('bp_code');

        foreach ($schedulesBySupplier as $bpCode => $schedules) {
            // Sort schedules by arrival_time (nulls last)
            $sortedSchedules = $schedules->sortBy(function ($schedule) {
                return $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i:s') : '23:59:59';
            })->values();

            if ($sortedSchedules->isEmpty()) {
                continue;
            }

            $scheduleIds = $sortedSchedules->pluck('id')->all();

            // Get all regular arrivals for this supplier & date
            $arrivals = ArrivalTransaction::where('arrival_type', 'regular')
                ->where('bp_code', $bpCode)
                ->whereDate('plan_delivery_date', $date)
                ->get();

            if ($arrivals->isEmpty()) {
                continue;
            }

            // Only assign arrivals that don't already belong to one of today's schedules
            $arrivalsToAssign = $arrivals->filter(function ($arrival) use ($scheduleIds) {
                return !$arrival->schedule_id || !in_array($arrival->schedule_id, $scheduleIds);
            });

            if ($arrivalsToAssign->isEmpty()) {
                continue;
            }

            // Sort arrivals by plan_delivery_time to preserve chronological order
            $sortedArrivals = $arrivalsToAssign->sortBy(function ($arrival) {
                return $arrival->plan_delivery_time
                    ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s')
                    : '23:59:59';
            })->values();

            $scheduleCount = $sortedSchedules->count();
            $scheduleIndex = 0;

            foreach ($sortedArrivals as $arrival) {
                // If there is no usable schedule, stop
                if ($scheduleCount === 0) {
                    break;
                }

                // If arrival has no plan_delivery_time, just use the current schedule index
                if (!$arrival->plan_delivery_time) {
                    $chosenIndex = $scheduleIndex;
                } else {
                    $arrivalTime = Carbon::parse($arrival->plan_delivery_time);
                    $bestIndex = $scheduleIndex;
                    $bestDiff = null;

                    // Search schedules from current index forward to keep order stable
                    for ($i = $scheduleIndex; $i < $scheduleCount; $i++) {
                        $scheduleTimeString = $sortedSchedules[$i]->arrival_time;
                        if (!$scheduleTimeString) {
                            continue;
                        }

                        $scheduleTime = Carbon::parse($scheduleTimeString);
                        $diff = abs($scheduleTime->diffInMinutes($arrivalTime, false));

                        if ($bestDiff === null || $diff < $bestDiff) {
                            $bestDiff = $diff;
                            $bestIndex = $i;
                        }
                    }

                    $chosenIndex = $bestIndex;
                }

                // Assign the chosen schedule to this arrival
                $chosenSchedule = $sortedSchedules[$chosenIndex];
                $arrival->schedule_id = $chosenSchedule->id;
                $arrival->save();

                // Move schedule index forward so later arrivals prefer same or later slots,
                // ensuring mapping follows chronological order (e.g. 09:00 -> 11:00, 12:00 -> 14:00)
                if ($scheduleIndex < $scheduleCount - 1) {
                    $scheduleIndex = $chosenIndex + 1;
                } else {
                    $scheduleIndex = $chosenIndex;
                }
            }
        }
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
            // For additional, we filter by schedule_id (which already matches schedule_date)
            // Don't filter by plan_delivery_date as it may differ from schedule_date
            // Eager load related arrival and its scan sessions for additional arrivals
            $arrivals = ArrivalTransaction::where('arrival_type', 'additional')
                ->where('bp_code', $schedule->bp_code)
                ->where('schedule_id', $schedule->id)
                ->with([
                    'scanSessions.scannedItems',
                    'relatedArrival.scanSessions.scannedItems'
                ])
                ->get();

            // Group arrivals by schedule_id and plan_delivery_time for additional
            // Include schedule_id in group key to separate different additional schedules
            $grouped = $arrivals->groupBy(function ($arrival) {
                $time = $arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00';
                return $arrival->bp_code . '_' . $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time . '_schedule_' . ($arrival->schedule_id ?? '0');
            });

            foreach ($grouped as $key => $group) {
                // For additional: prioritize arrival with warehouse_checkin_time for display
                // If no warehouse check-in, use any arrival with driver/vehicle
                // If none, use first arrival (additional may not have driver/vehicle)
                $displayArrival = $group->first(function ($arrival) {
                    return !empty($arrival->warehouse_checkin_time);
                });
                
                // Fallback to arrival with driver/vehicle if no warehouse check-in
                if (!$displayArrival) {
                    $displayArrival = $group->first(function ($arrival) {
                        return !empty($arrival->driver_name) && !empty($arrival->vehicle_plate);
                    });
                }
                
                // Final fallback: use first arrival (for additional without driver/vehicle)
                if (!$displayArrival) {
                    $displayArrival = $group->first();
                }
                
                // For other calculations, we still need a reference arrival
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
                $deliveredCount = 0;
                
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
                    
                    // For additional, always get scanned items from related arrival (additional doesn't have its own scan sessions)
                    $scannedItems = $arrival->scannedItems;
                    if ($arrival->arrival_type === 'additional' && $arrival->related_arrival_id) {
                        $relatedArrival = $arrival->relatedArrival ?? ArrivalTransaction::with('scanSessions.scannedItems')->find($arrival->related_arrival_id);
                        if ($relatedArrival && $relatedArrival->scanSessions) {
                            $relatedScanSession = $relatedArrival->scanSessions->first();
                            if ($relatedScanSession && $relatedScanSession->scannedItems) {
                                $scannedItems = $relatedScanSession->scannedItems;
                            }
                        }
                    }
                    $totalQuantityActual += $scannedItems->sum('scanned_quantity');
                    
                    // For additional, count as delivered if has warehouse_checkin_time (driver/vehicle may be null)
                    if ($arrival->arrival_type === 'additional') {
                        if (!empty($arrival->warehouse_checkin_time)) {
                            $deliveredCount++;
                        }
                    } else {
                        if (!empty($arrival->driver_name) && !empty($arrival->vehicle_plate)) {
                            $deliveredCount++;
                        }
                    }
                    
                    // Get check sheet status from scan session
                    // For additional, also check related arrival's scan sessions
                    $scanSession = $arrival->scanSessions->first();
                    if (!$scanSession && $arrival->arrival_type === 'additional' && $arrival->related_arrival_id) {
                        $relatedArrival = $arrival->relatedArrival ?? ArrivalTransaction::with('scanSessions')->find($arrival->related_arrival_id);
                        if ($relatedArrival && $relatedArrival->scanSessions) {
                            $scanSession = $relatedArrival->scanSessions->first();
                        }
                    }
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
                // For additional, only show DN from additional arrivals (deduplicate by dn_number)
                $dnList = [];
                $dnNumbersSeen = [];
                foreach ($group as $arrival) {
                    // Skip if this DN number already added (for additional, we only want one entry per DN)
                    if (in_array($arrival->dn_number, $dnNumbersSeen)) {
                        continue;
                    }
                    $dnNumbersSeen[] = $arrival->dn_number;
                    
                    // Get quantity_dn from SCM dn_detail
                    $dnQty = 0;
                    try {
                        $dnQty = ScmDnDetail::where('no_dn', $arrival->dn_number)->sum('dn_qty');
                    } catch (\Exception $e) {
                        $dnQty = $arrival->scannedItems->sum('expected_quantity');
                    }
                    
                    // For additional, get scanned items from related arrival's scan sessions
                    $scannedItemsForQty = $arrival->scannedItems;
                    if ($arrival->arrival_type === 'additional' && $arrival->related_arrival_id) {
                        $relatedArrival = $arrival->relatedArrival ?? ArrivalTransaction::with('scanSessions.scannedItems')->find($arrival->related_arrival_id);
                        if ($relatedArrival && $relatedArrival->scanSessions) {
                            $relatedScanSession = $relatedArrival->scanSessions->first();
                            // Use scanned items from related arrival if available (additional doesn't have its own scan sessions)
                            if ($relatedScanSession && $relatedScanSession->scannedItems) {
                                $scannedItemsForQty = $relatedScanSession->scannedItems;
                            }
                        }
                    }
                    $dnList[] = [
                        'dn_number' => $arrival->dn_number,
                        'quantity_dn' => $dnQty,
                        'quantity_actual' => $scannedItemsForQty->sum('scanned_quantity'),
                        'scan_status' => $this->getScanStatusForArrival($arrival),
                        'is_confirmed' => !empty($arrival->driver_name) && !empty($arrival->vehicle_plate),
                        'status' => $arrival->status,
                    ];
                }
                
                $groupedArrivals[] = [
                    'group_key' => $key,
                    'supplier_name' => $this->getSupplierName($schedule->bp_code),
                    'bp_code' => $schedule->bp_code,
                    'schedule' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i') : null,
                    'schedule_time_for_sort' => $schedule->arrival_time ? Carbon::parse($schedule->arrival_time)->format('H:i:s') : '00:00:00',
                    'dock' => $schedule->dock,
                    'vehicle_plate' => $displayArrival ? ($displayArrival->vehicle_plate ?? '-') : '-',
                    'driver_name' => $displayArrival ? ($displayArrival->driver_name ?? '-') : '-',
                    'security_time_in' => $displayArrival && $displayArrival->security_checkin_time ? Carbon::parse($displayArrival->security_checkin_time)->format('H:i') : '-',
                    'security_time_out' => $displayArrival && $displayArrival->security_checkout_time ? Carbon::parse($displayArrival->security_checkout_time)->format('H:i') : '-',
                    'security_duration' => $displayArrival && $displayArrival->security_duration ? $this->formatDuration($displayArrival->security_duration) : '-',
                    'warehouse_time_in' => $displayArrival && $displayArrival->warehouse_checkin_time ? Carbon::parse($displayArrival->warehouse_checkin_time)->format('H:i') : '-',
                    'warehouse_time_out' => $displayArrival && $displayArrival->warehouse_checkout_time ? Carbon::parse($displayArrival->warehouse_checkout_time)->format('H:i') : '-',
                    'warehouse_duration' => $displayArrival && $displayArrival->warehouse_duration ? $this->formatDuration($displayArrival->warehouse_duration) : '-',
                    // Use status from arrival_transactions table directly, not calculated
                    // Status should be set by backend logic elsewhere, not here
                    'arrival_status' => $displayArrival ? ($displayArrival->status ?? 'pending') : 'pending',
                    'quantity_dn' => $totalQuantityDn,
                    'quantity_actual' => $totalQuantityActual,
                    'scan_status' => $this->getScanStatusForGroup($group),
                    'dn_status' => $this->formatDeliveryCompliance($this->getWorstDeliveryCompliance($group)),
                    'dn_status_raw' => $this->getWorstDeliveryCompliance($group),
                    'label_part' => $allCheckSheetStatus['label_part'] ?? null,
                    'coa_msds' => $allCheckSheetStatus['coa_msds'] ?? null,
                    'packing' => $allCheckSheetStatus['packing'] ?? null,
                    'pic' => $displayArrival ? ($this->getPicName($displayArrival->pic_receiving) ?? '-') : '-',
                    'dn_count' => $group->count(),
                    'dn_delivered_count' => $deliveredCount,
                    'delivered_info' => "{$deliveredCount} of " . $group->count() . " delivered",
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

        // Parse group key: 
        // - Regular confirmed: bp_code_date_time_driver_<md5>_plate_<md5>
        // - Regular unconfirmed: bp_code_date_time_driver_null_plate_null
        // - Additional: bp_code_date_time_schedule_scheduleId
        $parts = explode('_', $groupKey);
        if (count($parts) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid group key format'
            ], 400);
        }
        $bpCode = $parts[0];
        $deliveryDate = $parts[1];
        
        // Check if this is additional (has schedule_ in group key)
        $isAdditional = false;
        $scheduleId = null;
        $deliveryTime = '';
        $driverHash = null;
        $plateHash = null;
        
        $scheduleIndex = array_search('schedule', $parts);
        if ($scheduleIndex !== false && isset($parts[$scheduleIndex + 1])) {
            // This is additional arrival
            $isAdditional = true;
            $scheduleId = (int)$parts[$scheduleIndex + 1];
            $deliveryTime = implode('_', array_slice($parts, 2, $scheduleIndex - 2));
        } else {
            // This is regular arrival - check for driver/plate in group key
            $driverIndex = array_search('driver', $parts);
            $plateIndex = array_search('plate', $parts);
            
            if ($driverIndex !== false && $plateIndex !== false && $driverIndex < $plateIndex) {
                // Has driver and plate info in group key
                $deliveryTime = implode('_', array_slice($parts, 2, $driverIndex - 2));
                $driverHash = $parts[$driverIndex + 1] ?? null;
                $plateHash = $parts[$plateIndex + 1] ?? null;
            } else {
                // Old format or no driver/plate info
                $deliveryTime = implode('_', array_slice($parts, 2));
            }
        }

        $query = ArrivalTransaction::forDate($deliveryDate)
            ->where('bp_code', $bpCode)
            ->with(['scanSessions.scannedItems', 'relatedArrival.scanSessions.scannedItems']);
        
        // Filter by arrival type to avoid duplicates
        if ($isAdditional) {
            $query->where('arrival_type', 'additional')
                  ->where('schedule_id', $scheduleId);
            if ($deliveryTime) {
                $query->where('plan_delivery_time', $deliveryTime);
            }
        } else {
            $query->where('arrival_type', 'regular');
            if ($deliveryTime) {
                $query->where('plan_delivery_time', $deliveryTime);
            }
            
            // Filter by driver and plate if specified in group key
            if ($driverHash && $plateHash) {
                if ($driverHash === 'null' && $plateHash === 'null') {
                    // Unconfirmed group - no driver/plate
                    $query->where(function ($q) {
                        $q->whereNull('driver_name')
                          ->orWhereNull('vehicle_plate')
                          ->orWhere('driver_name', '')
                          ->orWhere('vehicle_plate', '');
                    });
                } else {
                    // Confirmed group - match by driver and plate hash
                    // Also include unconfirmed arrivals (no driver/plate) with same bp_code, date, and time
                    // This ensures unconfirmed DNs attached to confirmed groups are included
                    $query->where(function ($q) use ($driverHash, $plateHash) {
                        // Confirmed arrivals matching driver/plate hash
                        $q->where(function ($subQ) use ($driverHash, $plateHash) {
                            $subQ->whereRaw('MD5(COALESCE(driver_name, "")) = ?', [$driverHash])
                                 ->whereRaw('MD5(COALESCE(vehicle_plate, "")) = ?', [$plateHash]);
                        })
                        // OR unconfirmed arrivals (attached to this confirmed group)
                        ->orWhere(function ($subQ) {
                            $subQ->where(function ($unconfirmedQ) {
                                $unconfirmedQ->whereNull('driver_name')
                                            ->orWhereNull('vehicle_plate')
                                            ->orWhere('driver_name', '')
                                            ->orWhere('vehicle_plate', '');
                            });
                        });
                    });
                }
            }
        }
        
        $arrivals = $query->get();

        $dnDetails = [];
        $dnNumbersSeen = []; // Deduplicate by DN number

        foreach ($arrivals as $arrival) {
            // Skip if this DN number already added
            if (in_array($arrival->dn_number, $dnNumbersSeen)) {
                continue;
            }
            $dnNumbersSeen[] = $arrival->dn_number;
            $scanSession = $arrival->scanSessions->first();
            $scannedItems = $scanSession ? $scanSession->scannedItems : collect();
            
            // For additional, get scanned items from related arrival if available
            if ($isAdditional && $arrival->arrival_type === 'additional' && $arrival->related_arrival_id && $scannedItems->isEmpty()) {
                $relatedArrival = $arrival->relatedArrival ?? ArrivalTransaction::with('scanSessions.scannedItems')->find($arrival->related_arrival_id);
                if ($relatedArrival && $relatedArrival->scanSessions) {
                    $relatedScanSession = $relatedArrival->scanSessions->first();
                    if ($relatedScanSession && $relatedScanSession->scannedItems) {
                        $scannedItems = $relatedScanSession->scannedItems;
                    }
                }
            }

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

        // Build scheduled datetime safely.
        // arrival_time might be stored as full datetime in DB; only take the time component.
        $scheduledDate = Carbon::parse($arrival->plan_delivery_date)->format('Y-m-d');
        $scheduledTimePart = $schedule->arrival_time
            ? Carbon::parse($schedule->arrival_time)->format('H:i:s')
            : '00:00:00';
        $scheduledTime = Carbon::parse($scheduledDate . ' ' . $scheduledTimePart);
        $actualTime = Carbon::parse($actualTime);
        $diffMinutes = $actualTime->diffInMinutes($scheduledTime, false);

        if ($diffMinutes <= -5) return 'Advance';
        if ($diffMinutes > 5) return 'Delay';
        return 'Ontime';
    }

    protected function getScanStatusForArrival($arrival)
    {
        // For additional arrivals, also check scan sessions from related regular arrival
        $sessions = $arrival->scanSessions;
        
        // If additional and has related arrival (eager loaded or need to load), also get sessions from related arrival
        if ($arrival->arrival_type === 'additional' && $arrival->related_arrival_id) {
            $relatedArrival = $arrival->relatedArrival ?? ArrivalTransaction::with('scanSessions')->find($arrival->related_arrival_id);
            if ($relatedArrival && $relatedArrival->scanSessions) {
                $sessions = $sessions->merge($relatedArrival->scanSessions);
            }
        }
        
        if ($sessions->isEmpty()) {
            return 'Pending';
        }
        
        $completedSessions = $sessions->where('status', 'completed')->count();
        $inProgressSessions = $sessions->where('status', 'in_progress')->count();
        $totalSessions = $sessions->count();
        
        // If all sessions are completed
        if ($completedSessions === $totalSessions && $totalSessions > 0) {
            return 'Completed';
        }
        
        // If there are any in_progress sessions or any completed sessions (but not all completed)
        if ($inProgressSessions > 0 || $completedSessions > 0) {
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

    /**
     * Get worst delivery compliance status from a group of arrivals
     * Priority order (worst to best): no_show > delay > partial_delivery > incomplete_qty > on_commitment > pending
     */
    protected function getWorstDeliveryCompliance($group)
    {
        $priorities = [
            'pending' => 0,
            'on_commitment' => 1,
            'incomplete_qty' => 2,
            'partial_delivery' => 3,
            'delay' => 4,
            'no_show' => 5,
        ];

        $worstStatus = 'pending';
        $worstPriority = 0;

        foreach ($group as $arrival) {
            $compliance = $arrival->delivery_compliance ?? 'pending';
            $priority = $priorities[$compliance] ?? 0;
            
            if ($priority > $worstPriority) {
                $worstPriority = $priority;
                $worstStatus = $compliance;
            }
        }

        return $worstStatus;
    }

    /**
     * Format delivery compliance status for display
     */
    protected function formatDeliveryCompliance($compliance)
    {
        $labels = [
            'pending' => 'Pending',
            'on_commitment' => 'On Commitment',
            'incomplete_qty' => 'Incomplete Qty',
            'partial_delivery' => 'Outstanding DN',
            'delay' => 'Delay',
            'no_show' => 'No Show',
        ];

        return $labels[$compliance] ?? ucfirst(str_replace('_', ' ', $compliance));
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
