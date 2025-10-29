<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Services\AuthService;
use Carbon\Carbon;

class ArrivalScheduleController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get arrival schedule data for specific date
     */
    public function index(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $date = $request->date;
        $dayName = Carbon::parse($date)->format('l'); // Get day name (Monday, Tuesday, etc.)

        // Get regular arrivals for the date
        $regularArrivals = $this->getRegularArrivals($date, $dayName);
        
        // Get additional arrivals for the date
        $additionalArrivals = $this->getAdditionalArrivals($date);

        // Get summary statistics
        $summary = $this->getSummaryStatistics($date);

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'day_name' => $dayName,
                'regular_arrivals' => $regularArrivals,
                'additional_arrivals' => $additionalArrivals,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Get regular arrivals based on schedule
     */
    protected function getRegularArrivals($date, $dayName)
    {
        // Get schedules for the day
        $schedules = ArrivalSchedule::regular()
            ->forDay(strtolower($dayName))
            ->with('arrivalTransactions')
            ->get();

        $regularArrivals = [];

        foreach ($schedules as $schedule) {
            // Get arrivals for this schedule on the specific date
            $arrivals = ArrivalTransaction::regular()
                ->forDate($date)
                ->where('bp_code', $schedule->bp_code)
                ->where('plan_delivery_time', $schedule->arrival_time)
                ->with(['scanSessions'])
                ->get();

            if ($arrivals->isNotEmpty()) {
                $firstArrival = $arrivals->first();
                
                $regularArrivals[] = [
                    'schedule_id' => $schedule->id,
                    'supplier_name' => $this->getSupplierName($schedule->bp_code),
                    'bp_code' => $schedule->bp_code,
                    'schedule_time' => $schedule->arrival_time,
                    'dock' => $schedule->dock,
                    'vehicle_plate' => $firstArrival->vehicle_plate,
                    'driver_name' => $firstArrival->driver_name,
                    'security_checkin_time' => $firstArrival->security_checkin_time,
                    'security_checkout_time' => $firstArrival->security_checkout_time,
                    'security_duration' => $firstArrival->security_duration,
                    'warehouse_checkin_time' => $firstArrival->warehouse_checkin_time,
                    'warehouse_checkout_time' => $firstArrival->warehouse_checkout_time,
                    'warehouse_duration' => $firstArrival->warehouse_duration,
                    'arrival_status' => $this->calculateArrivalStatus($firstArrival, $schedule),
                    'quantity_dn' => $firstArrival->total_quantity_dn,
                    'quantity_actual' => $firstArrival->total_quantity_actual,
                    'scan_status' => $firstArrival->scan_status,
                    'pic' => $this->getPicName($firstArrival->pic_receiving),
                    'dn_count' => $arrivals->count(),
                    'dn_numbers' => $arrivals->pluck('dn_number')->toArray(),
                    'dns' => $this->formatDnNumbers($arrivals->pluck('dn_number')->toArray()),
                ];
            }
        }

        return $regularArrivals;
    }

    /**
     * Get additional arrivals for the date
     */
    protected function getAdditionalArrivals($date)
    {
        $arrivals = ArrivalTransaction::additional()
            ->forDate($date)
            ->with(['schedule', 'scanSessions'])
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
                'arrival_status' => $this->calculateArrivalStatus($firstArrival, $firstArrival->schedule),
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
     * Get summary statistics for the date
     */
    protected function getSummaryStatistics($date)
    {
        $totalArrivals = ArrivalTransaction::forDate($date)->count();
        $regularArrivals = ArrivalTransaction::forDate($date)->regular()->count();
        $additionalArrivals = ArrivalTransaction::forDate($date)->additional()->count();
        $onTimeArrivals = ArrivalTransaction::forDate($date)->where('status', 'on_time')->count();
        $delayArrivals = ArrivalTransaction::forDate($date)->where('status', 'delay')->count();
        $advanceArrivals = ArrivalTransaction::forDate($date)->where('status', 'advance')->count();
        $pendingArrivals = ArrivalTransaction::forDate($date)->where('status', 'pending')->count();

        return [
            'total_arrivals' => $totalArrivals,
            'regular_arrivals' => $regularArrivals,
            'additional_arrivals' => $additionalArrivals,
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
        $request->validate([
            'group_key' => 'required|string',
            'date' => 'required|date',
        ]);

        [$deliveryDate, $deliveryTime] = explode('_', $request->group_key);

        $arrivals = ArrivalTransaction::forDate($deliveryDate)
            ->where('plan_delivery_time', $deliveryTime)
            ->with(['scanSessions.scannedItems'])
            ->get();

        $dnDetails = [];

        foreach ($arrivals as $arrival) {
            $scanSession = $arrival->scanSessions->first();
            $scannedItems = $scanSession ? $scanSession->scannedItems : collect();

            $dnDetails[] = [
                'dn_number' => $arrival->dn_number,
                'arrival_type' => $arrival->arrival_type,
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
     * Get schedule performance for date range
     */
    public function getPerformance(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'bp_code' => 'nullable|string',
        ]);

        $startDate = $request->start_date;
        $endDate = $request->end_date;

        $query = ArrivalTransaction::whereBetween('plan_delivery_date', [$startDate, $endDate]);

        if ($request->bp_code) {
            $query->where('bp_code', $request->bp_code);
        }

        $arrivals = $query->get();

        $performance = [
            'total_arrivals' => $arrivals->count(),
            'on_time' => $arrivals->where('status', 'on_time')->count(),
            'delay' => $arrivals->where('status', 'delay')->count(),
            'advance' => $arrivals->where('status', 'advance')->count(),
            'pending' => $arrivals->where('status', 'pending')->count(),
            'average_security_duration' => $arrivals->avg('security_duration'),
            'average_warehouse_duration' => $arrivals->avg('warehouse_duration'),
        ];

        $performance['on_time_percentage'] = $performance['total_arrivals'] > 0 
            ? round(($performance['on_time'] / $performance['total_arrivals']) * 100, 2) 
            : 0;

        return response()->json([
            'success' => true,
            'data' => $performance
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

    protected function getPicName($picId)
    {
        if (!$picId) return null;
        
        $user = \App\Models\External\SphereUser::find($picId);
        return $user ? $user->name : null;
    }

    protected function calculateArrivalStatus($arrival, $schedule)
    {
        if (!$schedule) return 'pending';

        $scheduledTime = Carbon::parse($arrival->plan_delivery_date . ' ' . $schedule->arrival_time);
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
