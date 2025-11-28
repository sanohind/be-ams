<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Models\External\Visitor;
use App\Services\AuthService;
use Carbon\Carbon;

class ArrivalCheckController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get arrivals available for check-in/check-out
     * Now follows supplier schedule from arrival_schedule table like Dashboard
     */
    public function index(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $type = $request->get('type', 'checkin'); // checkin or checkout
        
        // Ensure latest security check-in/out data is reflected by syncing from Visitor DB
        // before fetching the list. This keeps UI consistent without requiring a manual sync.
        $this->syncVisitorsForDate($date);

        // Get regular arrivals following schedule (like Dashboard)
        $regularArrivals = $this->getRegularArrivalsForCheck($date, $type);
        
        // Get additional arrivals (filter by schedule_date)
        $additionalArrivals = $this->getAdditionalArrivalsForCheck($date, $type);
        
        // Group regular arrivals by date, time, driver, and plate
        $regularGrouped = $regularArrivals->groupBy(function ($arrival) {
            $time = $arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00';
            $driver = $arrival->driver_name ?? 'null';
            $plate = $arrival->vehicle_plate ?? 'null';
            
            // If driver/plate is null, use special marker
            if ($driver === 'null' || $plate === 'null') {
                return $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time . '_driver_null_plate_null';
            }
            
            return $arrival->bp_code . '_' . $arrival->plan_delivery_date->format('Y-m-d') . '_' . $time . '_driver_' . md5($driver) . '_plate_' . md5($plate);
        });
        
        // Group additional arrivals by schedule_id and time
        $additionalGrouped = $additionalArrivals->groupBy(function ($arrival) {
            if ($arrival->schedule_id) {
                return 'schedule_' . $arrival->schedule_id . '_' . ($arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00');
            }
            return $arrival->plan_delivery_date->format('Y-m-d') . '_' . ($arrival->plan_delivery_time ? Carbon::parse($arrival->plan_delivery_time)->format('H:i:s') : '00:00:00');
        });
        
        // Merge groups - convert both to arrays and merge, then convert back to collection
        $grouped = collect(array_merge($regularGrouped->all(), $additionalGrouped->all()));

        $groupedArrivals = [];

        foreach ($grouped as $key => $group) {
            $firstArrival = $group->first();
            
            $groupedArrivals[] = [
                'group_key' => $key,
                'supplier_name' => $this->getSupplierName($firstArrival->bp_code),
                'bp_code' => $firstArrival->bp_code,
                'driver_name' => $firstArrival->driver_name,
                'vehicle_plate' => $firstArrival->vehicle_plate,
                'dock' => $firstArrival->schedule ? $firstArrival->schedule->dock : null,
                'arrival_type' => $firstArrival->arrival_type,
                'security_checkin_time' => $firstArrival->security_checkin_time,
                'warehouse_checkin_time' => $firstArrival->warehouse_checkin_time,
                'warehouse_checkout_time' => $firstArrival->warehouse_checkout_time,
                'dn_count' => $group->count(),
                'dn_numbers' => $group->pluck('dn_number')->toArray(),
                'arrival_ids' => $group->pluck('id')->toArray(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'type' => $type,
                'arrivals' => $groupedArrivals,
            ]
        ]);
    }

    /**
     * Get regular arrivals for check-in/check-out following supplier schedule
     * Master data is from arrival_schedule - only show suppliers scheduled for this day
     */
    protected function getRegularArrivalsForCheck($date, $type)
    {
        $carbonDate = Carbon::parse($date);
        $dayName = strtolower($carbonDate->format('l')); // monday, tuesday, etc.
        
        // Get all active schedules for this day (this is the master data)
        $activeSchedules = ArrivalSchedule::regular()
            ->where('day_name', $dayName)
            ->orderBy('arrival_time')
            ->get();

        // First, ensure all regular arrivals for this supplier & date
        // are linked to the most appropriate schedule using a "nearest schedule" strategy.
        $this->assignRegularArrivalsToNearestSchedules($date, $activeSchedules);

        $allArrivals = collect();

        foreach ($activeSchedules as $schedule) {
            // After assignment above, arrivals for this supplier & date
            // that belong to this schedule already have schedule_id = $schedule->id
            $query = ArrivalTransaction::where('arrival_type', 'regular')
                ->where('bp_code', $schedule->bp_code)
                ->whereDate('plan_delivery_date', $date)
                ->where('schedule_id', $schedule->id)
                ->with(['schedule']);

            // For checkin: show only regular with driver/plate
            // For checkout: must have warehouse checkin time
            if ($type === 'checkin') {
                $query->whereNotNull('driver_name')
                      ->whereNotNull('vehicle_plate')
                      ->whereNull('warehouse_checkin_time');
            } else {
                $query->whereNotNull('warehouse_checkin_time')
                      ->whereNull('warehouse_checkout_time');
            }

            $arrivals = $query->get();
            $allArrivals = $allArrivals->merge($arrivals);
        }

        return $allArrivals;
    }

    /**
     * Get additional arrivals for check-in/check-out
     * Filter by schedule_date from arrival_schedule
     */
    protected function getAdditionalArrivalsForCheck($date, $type)
    {
        $query = ArrivalTransaction::with(['schedule'])
            ->where('arrival_type', 'additional')
            ->whereHas('schedule', function ($scheduleQuery) use ($date) {
                $scheduleQuery->where('schedule_date', $date);
            });

        // For checkin: show all additional
        // For checkout: must have warehouse checkin time
        if ($type === 'checkin') {
            $query->whereNull('warehouse_checkin_time');
        } else {
            $query->whereNotNull('warehouse_checkin_time')
                  ->whereNull('warehouse_checkout_time');
        }

        return $query->get();
    }

    /**
     * Assign regular arrivals to the nearest schedules for a given date and set of active schedules.
     * This is the same logic as DashboardController to ensure consistency.
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
                // ensuring mapping follows chronological order
                if ($scheduleIndex < $scheduleCount - 1) {
                    $scheduleIndex = $chosenIndex + 1;
                } else {
                    $scheduleIndex = $chosenIndex;
                }
            }
        }
    }

    /**
     * Minimal sync helper so arrival list always reflects latest Visitor DB
     */
    protected function syncVisitorsForDate(string $date): void
    {
        // Fetch visitors for the date that have checked in (checkout may be null if still on site)
        $visitors = Visitor::forDate($date)
            ->checkedIn()
            ->get();

        if ($visitors->isEmpty()) {
            return;
        }

        foreach ($visitors as $visitor) {
            if (!$visitor->bp_code || !$visitor->visitor_name || !$visitor->visitor_vehicle) {
                continue;
            }

            $arrivalsQuery = ArrivalTransaction::forDate($date)
                ->where('bp_code', $visitor->bp_code)
                ->where('driver_name', $visitor->visitor_name)
                ->where('vehicle_plate', $visitor->visitor_vehicle);

            if ($visitor->plan_delivery_time) {
                $time = Carbon::parse($visitor->plan_delivery_time)->format('H:i:s');
                $arrivalsQuery->whereTime('plan_delivery_time', $time);
            }

            $arrivals = $arrivalsQuery->get();

            foreach ($arrivals as $arrival) {
                $dirty = false;
                if (is_null($arrival->visitor_id)) {
                    $arrival->visitor_id = $visitor->visitor_id;
                    $dirty = true;
                }
                if (is_null($arrival->security_checkin_time) && $visitor->visitor_checkin) {
                    $arrival->security_checkin_time = $visitor->visitor_checkin;
                    $dirty = true;
                }
                // Also sync checkout if available
                if (is_null($arrival->security_checkout_time) && $visitor->visitor_checkout) {
                    $arrival->security_checkout_time = $visitor->visitor_checkout;
                    $dirty = true;
                }
                if ($dirty) {
                    // Don't update delivery_compliance here - it should be updated by nightly worker
                    $arrival->save();
                    $arrival->calculateSecurityDuration();
                }
            }
        }
    }

    /**
     * Check in driver to warehouse
     */
    public function checkin(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_ids' => 'required|array',
            'arrival_ids.*' => 'exists:arrival_transactions,id',
        ]);

        $arrivals = ArrivalTransaction::whereIn('id', $request->arrival_ids)->get();

        // Validate all arrivals are from the same group
        $firstArrival = $arrivals->first();
        $groupKey = $firstArrival->plan_delivery_date . '_' . $firstArrival->plan_delivery_time;

        foreach ($arrivals as $arrival) {
            $arrivalGroupKey = $arrival->plan_delivery_date . '_' . $arrival->plan_delivery_time;
            if ($arrivalGroupKey !== $groupKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'All arrivals must be from the same delivery group'
                ], 400);
            }

            // For regular arrivals, ensure driver and vehicle plate are present
            if ($arrival->arrival_type === 'regular') {
                if (empty($arrival->driver_name) || empty($arrival->vehicle_plate)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Regular arrivals must have driver name and vehicle plate before check-in'
                    ], 400);
                }
            }

            if ($arrival->warehouse_checkin_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver has already checked in to warehouse'
                ], 400);
            }
        }

        // Update all arrivals with warehouse check-in time
        // For regular arrivals, only update those with driver/vehicle
        // For additional arrivals, update all
        foreach ($arrivals as $arrival) {
            if ($arrival->arrival_type === 'regular') {
                // Only update if has driver and vehicle
                if (!empty($arrival->driver_name) && !empty($arrival->vehicle_plate)) {
                    $arrival->warehouse_checkin_time = now();
                    $arrival->pic_receiving = $user ? $user->id : null; // Allow null for public access
                    // Don't update delivery_compliance here - it should be updated by nightly worker
                    $arrival->save();
                }
            } else {
                // Additional arrivals - update all
                $arrival->warehouse_checkin_time = now();
                $arrival->pic_receiving = $user ? $user->id : null; // Allow null for public access
                // Don't update delivery_compliance here - it should be updated by nightly worker
                $arrival->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Driver checked in to warehouse successfully',
            'data' => [
                'checkin_time' => now(),
                'pic' => $user ? $user->name : 'System',
                'arrival_count' => $arrivals->count(),
            ]
        ]);
    }

    /**
     * Check out driver from warehouse
     */
    public function checkout(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'arrival_ids' => 'required|array',
            'arrival_ids.*' => 'exists:arrival_transactions,id',
        ]);

        $arrivals = ArrivalTransaction::whereIn('id', $request->arrival_ids)->get();

        // Validate all arrivals are from the same group
        $firstArrival = $arrivals->first();
        $groupKey = $firstArrival->plan_delivery_date . '_' . $firstArrival->plan_delivery_time;

        foreach ($arrivals as $arrival) {
            $arrivalGroupKey = $arrival->plan_delivery_date . '_' . $arrival->plan_delivery_time;
            if ($arrivalGroupKey !== $groupKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'All arrivals must be from the same delivery group'
                ], 400);
            }

            // For regular arrivals, ensure driver and vehicle plate are present
            if ($arrival->arrival_type === 'regular') {
                if (empty($arrival->driver_name) || empty($arrival->vehicle_plate)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Regular arrivals must have driver name and vehicle plate before check-out'
                    ], 400);
                }
            }

            if (!$arrival->warehouse_checkin_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver must check in to warehouse first'
                ], 400);
            }

            if ($arrival->warehouse_checkout_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver has already checked out from warehouse'
                ], 400);
            }
        }

        // Update all arrivals with warehouse check-out time
        // For regular arrivals, only update those with driver/vehicle
        // For additional arrivals, update all
        foreach ($arrivals as $arrival) {
            if ($arrival->arrival_type === 'regular') {
                // Only update if has driver and vehicle
                if (!empty($arrival->driver_name) && !empty($arrival->vehicle_plate)) {
                    $arrival->warehouse_checkout_time = now();
                    $arrival->completed_at = now();
                    // Don't update delivery_compliance here - it should be updated by nightly worker
                    $arrival->save();
                    
                    // Calculate warehouse duration
                    $arrival->calculateWarehouseDuration();
                }
            } else {
                // Additional arrivals - update all
                $arrival->warehouse_checkout_time = now();
                $arrival->completed_at = now();
                // Don't update delivery_compliance here - it should be updated by nightly worker
                $arrival->save();
                
                // Calculate warehouse duration
                $arrival->calculateWarehouseDuration();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Driver checked out from warehouse successfully',
            'data' => [
                'checkout_time' => now(),
                'arrival_count' => $arrivals->count(),
            ]
        ]);
    }

    /**
     * Sync visitor data with arrival transactions
     */
    public function syncVisitorData(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $visitors = Visitor::forDate($date)
            ->checkedIn()
            ->get();

        $syncedCount = 0;

        foreach ($visitors as $visitor) {
            if (!$visitor->bp_code || !$visitor->visitor_name || !$visitor->visitor_vehicle) {
                continue;
            }

            $arrivals = ArrivalTransaction::forDate($date)
                ->where('bp_code', $visitor->bp_code)
                ->where('driver_name', $visitor->visitor_name)
                ->where('vehicle_plate', $visitor->visitor_vehicle)
                ->whereNull('visitor_id')
                ->get();

            foreach ($arrivals as $arrival) {
                $arrival->visitor_id = $visitor->visitor_id;
                $arrival->security_checkin_time = $visitor->visitor_checkin;
                $arrival->security_checkout_time = $visitor->visitor_checkout;
                // Don't update delivery_compliance here - it should be updated by nightly worker
                $arrival->save();

                $arrival->calculateSecurityDuration();
                $syncedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Synced {$syncedCount} arrival transactions with visitor data",
            'data' => [
                'synced_count' => $syncedCount,
                'total_visitors' => $visitors->count(),
            ]
        ]);
    }

    /**
     * Get check-in/check-out statistics
     */
    public function getStatistics(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());

        $totalArrivals = ArrivalTransaction::forDate($date)->count();
        $checkedInSecurity = ArrivalTransaction::forDate($date)
            ->whereNotNull('security_checkin_time')
            ->count();
        $checkedInWarehouse = ArrivalTransaction::forDate($date)
            ->whereNotNull('warehouse_checkin_time')
            ->count();
        $checkedOutWarehouse = ArrivalTransaction::forDate($date)
            ->whereNotNull('warehouse_checkout_time')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_arrivals' => $totalArrivals,
                'checked_in_security' => $checkedInSecurity,
                'checked_in_warehouse' => $checkedInWarehouse,
                'checked_out_warehouse' => $checkedOutWarehouse,
                'security_checkin_rate' => $totalArrivals > 0 ? round(($checkedInSecurity / $totalArrivals) * 100, 2) : 0,
                'warehouse_checkin_rate' => $totalArrivals > 0 ? round(($checkedInWarehouse / $totalArrivals) * 100, 2) : 0,
                'warehouse_checkout_rate' => $totalArrivals > 0 ? round(($checkedOutWarehouse / $totalArrivals) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Helper method to get supplier name
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
}
