<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
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
     */
    public function index(Request $request)
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $type = $request->get('type', 'checkin'); // checkin or checkout

        $query = ArrivalTransaction::forDate($date)
            ->whereNotNull('security_checkin_time')
            ->with(['schedule']);

        if ($type === 'checkin') {
            $query->whereNull('warehouse_checkin_time');
        } else {
            $query->whereNotNull('warehouse_checkin_time')
                  ->whereNull('warehouse_checkout_time');
        }

        $arrivals = $query->get()
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

            if ($arrival->warehouse_checkin_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver has already checked in to warehouse'
                ], 400);
            }
        }

        // Update all arrivals with warehouse check-in time
        foreach ($arrivals as $arrival) {
            $arrival->update([
                'warehouse_checkin_time' => now(),
                'pic_receiving' => $user->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Driver checked in to warehouse successfully',
            'data' => [
                'checkin_time' => now(),
                'pic' => $user->name,
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
        foreach ($arrivals as $arrival) {
            $arrival->update([
                'warehouse_checkout_time' => now(),
            ]);
            
            // Calculate warehouse duration
            $arrival->calculateWarehouseDuration();
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
                $arrival->update([
                    'visitor_id' => $visitor->visitor_id,
                    'security_checkin_time' => $visitor->visitor_checkin,
                ]);
                
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
