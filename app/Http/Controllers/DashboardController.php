<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Models\External\Visitor;
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
     * Get regular arrivals grouped by supplier
     */
    protected function getRegularArrivals($date)
    {
        $arrivals = ArrivalTransaction::where('arrival_type', 'regular')
            ->whereDate('plan_delivery_date', $date)
            ->with(['schedule', 'scanSessions'])
            ->get()
            ->groupBy(function ($arrival) {
                return $arrival->plan_delivery_date . '_' . ($arrival->plan_delivery_time ?? '00:00:00');
            });

        $groupedArrivals = [];

        foreach ($arrivals as $key => $group) {
            $firstArrival = $group->first();
            
            $groupedArrivals[] = [
                'group_key' => $key,
                'supplier_name' => $this->getSupplierName($firstArrival->bp_code),
                'bp_code' => $firstArrival->bp_code,
                'schedule_time' => $firstArrival->schedule ? $firstArrival->schedule->arrival_time : null,
                'dock' => $firstArrival->schedule ? $firstArrival->schedule->dock : null,
                'vehicle_plate' => $firstArrival->vehicle_plate,
                'driver_name' => $firstArrival->driver_name,
                'security_checkin_time' => $firstArrival->security_checkin_time,
                'security_checkout_time' => $firstArrival->security_checkout_time,
                'security_duration' => $firstArrival->security_duration,
                'warehouse_checkin_time' => $firstArrival->warehouse_checkin_time,
                'warehouse_checkout_time' => $firstArrival->warehouse_checkout_time,
                'warehouse_duration' => $firstArrival->warehouse_duration,
                'arrival_status' => $this->calculateArrivalStatus($firstArrival),
                'quantity_dn' => $firstArrival->total_quantity_dn,
                'quantity_actual' => $firstArrival->total_quantity_actual,
                'scan_status' => $firstArrival->scan_status,
                'pic' => $this->getPicName($firstArrival->pic_receiving),
                'dn_count' => $group->count(),
                'dn_numbers' => $group->pluck('dn_number')->toArray(),
                'dns' => $this->formatDnNumbers($group->pluck('dn_number')->toArray()),
            ];
        }

        return $groupedArrivals;
    }

    /**
     * Get additional arrivals
     */
    protected function getAdditionalArrivals($date)
    {
        $arrivals = ArrivalTransaction::where('arrival_type', 'additional')
            ->whereDate('plan_delivery_date', $date)
            ->with(['schedule', 'scanSessions'])
            ->get()
            ->groupBy(function ($arrival) {
                return $arrival->plan_delivery_date . '_' . ($arrival->plan_delivery_time ?? '00:00:00');
            });

        $groupedArrivals = [];

        foreach ($arrivals as $key => $group) {
            $firstArrival = $group->first();
            
            $groupedArrivals[] = [
                'group_key' => $key,
                'supplier_name' => $this->getSupplierName($firstArrival->bp_code),
                'bp_code' => $firstArrival->bp_code,
                'schedule_time' => $firstArrival->schedule ? $firstArrival->schedule->arrival_time : null,
                'dock' => $firstArrival->schedule ? $firstArrival->schedule->dock : null,
                'vehicle_plate' => $firstArrival->vehicle_plate,
                'driver_name' => $firstArrival->driver_name,
                'security_checkin_time' => $firstArrival->security_checkin_time,
                'security_checkout_time' => $firstArrival->security_checkout_time,
                'security_duration' => $firstArrival->security_duration,
                'warehouse_checkin_time' => $firstArrival->warehouse_checkin_time,
                'warehouse_checkout_time' => $firstArrival->warehouse_checkout_time,
                'warehouse_duration' => $firstArrival->warehouse_duration,
                'arrival_status' => $this->calculateArrivalStatus($firstArrival),
                'quantity_dn' => $firstArrival->total_quantity_dn,
                'quantity_actual' => $firstArrival->total_quantity_actual,
                'scan_status' => $firstArrival->scan_status,
                'pic' => $this->getPicName($firstArrival->pic_receiving),
                'dn_count' => $group->count(),
                'dn_numbers' => $group->pluck('dn_number')->toArray(),
                'dns' => $this->formatDnNumbers($group->pluck('dn_number')->toArray()),
            ];
        }

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

        [$deliveryDate, $deliveryTime] = explode('_', $groupKey);

        $arrivals = ArrivalTransaction::forDate($deliveryDate)
            ->where('plan_delivery_time', $deliveryTime)
            ->with(['scanSessions.scannedItems'])
            ->get();

        $dnDetails = [];

        foreach ($arrivals as $arrival) {
            $scanSession = $arrivals->first()->scanSessions->first();
            $scannedItems = $scanSession ? $scanSession->scannedItems : collect();

            $dnDetails[] = [
                'dn_number' => $arrival->dn_number,
                'scan_status' => $arrival->scan_status,
                'quantity_dn' => $scannedItems->sum('expected_quantity'),
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
        // Get supplier name from settings or SCM
        $supplierData = \App\Models\Setting::getValue("supplier_{$bpCode}");
        if ($supplierData) {
            $data = json_decode($supplierData, true);
            return $data['name'] ?? $bpCode;
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

    protected function calculateArrivalStatus($arrival)
    {
        if (!$arrival->schedule) return 'pending';

        $scheduledTime = Carbon::parse($arrival->plan_delivery_date . ' ' . $arrival->schedule->arrival_time);
        $actualTime = $arrival->warehouse_checkin_time;

        if (!$actualTime) return 'pending';

        $actualTime = Carbon::parse($actualTime);
        $diffMinutes = $actualTime->diffInMinutes($scheduledTime, false);

        if ($diffMinutes <= 15) return 'on_time';
        if ($diffMinutes > 15) return 'delay';
        if ($diffMinutes < -15) return 'advance';

        return 'on_time';
    }

    protected function formatDnNumbers($dnNumbers)
    {
        $count = count($dnNumbers);
        return "{$count} DN";
    }
}
