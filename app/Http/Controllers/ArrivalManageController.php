<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalSchedule;
use App\Models\ArrivalTransaction;
use App\Models\External\ScmBusinessPartner;
use App\Services\AuthService;
use Carbon\Carbon;

class ArrivalManageController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Get arrival schedules
     */
    public function index(Request $request)
    {
        $schedules = ArrivalSchedule::with('arrivalTransactions')
            ->orderBy('bp_code')
            ->orderBy('day_name')
            ->orderBy('arrival_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules
        ]);
    }

    /**
     * Store new arrival schedule
     */
    public function store(Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'bp_code' => 'required|string|max:25',
            'day_name' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'arrival_type' => 'required|in:regular,additional',
            'arrival_time' => 'required|date_format:H:i',
            'departure_time' => 'nullable|date_format:H:i',
            'dock' => 'nullable|string|max:25',
            'schedule_date' => 'nullable|date',
            'arrival_ids' => 'nullable|array',
            'arrival_ids.*' => 'exists:arrival_transactions,id',
        ]);

        $scheduleData = [
            'bp_code' => $request->bp_code,
            'day_name' => $request->day_name,
            'arrival_type' => $request->arrival_type,
            'arrival_time' => $request->arrival_time,
            'departure_time' => $request->departure_time,
            'dock' => $request->dock,
            'created_by' => $user->id,
        ];

        // For additional schedules, set specific date
        if ($request->arrival_type === 'additional') {
            $scheduleData['schedule_date'] = $request->schedule_date;
        }

        $schedule = ArrivalSchedule::create($scheduleData);

        // If additional schedule with specific arrivals, duplicate them
        if ($request->arrival_type === 'additional' && $request->arrival_ids) {
            $this->duplicateArrivalsForAdditionalSchedule($schedule, $request->arrival_ids);
        }

        return response()->json([
            'success' => true,
            'message' => 'Arrival schedule created successfully',
            'data' => $schedule
        ], 201);
    }

    /**
     * Update arrival schedule
     */
    public function update(Request $request, $id)
    {
        $user = $this->authService->getUserFromRequest($request);

        $schedule = ArrivalSchedule::findOrFail($id);

        $request->validate([
            'bp_code' => 'required|string|max:25',
            'day_name' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'arrival_type' => 'required|in:regular,additional',
            'arrival_time' => 'required|date_format:H:i',
            'departure_time' => 'nullable|date_format:H:i',
            'dock' => 'nullable|string|max:25',
            'schedule_date' => 'nullable|date',
        ]);

        $schedule->update([
            'bp_code' => $request->bp_code,
            'day_name' => $request->day_name,
            'arrival_type' => $request->arrival_type,
            'arrival_time' => $request->arrival_time,
            'departure_time' => $request->departure_time,
            'dock' => $request->dock,
            'schedule_date' => $request->arrival_type === 'additional' ? $request->schedule_date : null,
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Arrival schedule updated successfully',
            'data' => $schedule
        ]);
    }

    /**
     * Delete arrival schedule
     */
    public function destroy($id)
    {
        $schedule = ArrivalSchedule::findOrFail($id);
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Arrival schedule deleted successfully'
        ]);
    }

    /**
     * Get suppliers for dropdown
     */
    public function getSuppliers()
    {
        $suppliers = ScmBusinessPartner::suppliers()
            ->active()
            ->select('bp_code', 'bp_name')
            ->orderBy('bp_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Get available arrivals for additional schedule
     */
    public function getAvailableArrivals(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
            'bp_code' => 'nullable|string',
        ]);

        $query = ArrivalTransaction::regular()
            ->whereNull('schedule_id');

        if ($request->bp_code) {
            $query->where('bp_code', $request->bp_code);
        }

        if ($request->filled('date')) {
            $targetDate = Carbon::parse($request->date);
            $rangeStart = $targetDate->copy()->subDays(7)->toDateString();
            $rangeEnd = $targetDate->copy()->addDays(7)->toDateString();
            $query->whereBetween('plan_delivery_date', [$rangeStart, $rangeEnd]);
        }

        $arrivals = $query
            ->orderBy('plan_delivery_date')
            ->orderBy('plan_delivery_time')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $arrivals
        ]);
    }

    /**
     * Get all arrival transactions for a supplier (for DN selection)
     */
    public function getArrivalTransactions(Request $request)
    {
        $request->validate([
            'bp_code' => 'required|string',
        ]);

        $query = ArrivalTransaction::where('bp_code', $request->bp_code);

        $arrivals = $query
            ->orderBy('plan_delivery_date', 'desc')
            ->orderBy('plan_delivery_time', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $arrivals
        ]);
    }

    /**
     * Duplicate arrivals for additional schedule
     */
    protected function duplicateArrivalsForAdditionalSchedule(ArrivalSchedule $schedule, array $arrivalIds)
    {
        $originalArrivals = ArrivalTransaction::whereIn('id', $arrivalIds)->get();

        foreach ($originalArrivals as $originalArrival) {
            // Check if an additional arrival with the same DN/PO already exists for this schedule
            // This prevents duplicate entries within the same additional schedule
            $existingAdditional = ArrivalTransaction::where('dn_number', $originalArrival->dn_number)
                ->where('po_number', $originalArrival->po_number)
                ->where('arrival_type', 'additional')
                ->where('schedule_id', $schedule->id)
                ->first();

            // Skip if this DN/PO combination already exists as additional for this schedule
            if ($existingAdditional) {
                continue;
            }

            $duplicate = ArrivalTransaction::create([
                'dn_number' => $originalArrival->dn_number,
                'po_number' => $originalArrival->po_number,
                'arrival_type' => 'additional',
                // Keep original plan_delivery_date to show this DN was supposed to be delivered on the original date
                // The schedule_date in ArrivalSchedule shows when it's actually being delivered
                'plan_delivery_date' => $originalArrival->plan_delivery_date,
                'plan_delivery_time' => $originalArrival->plan_delivery_time,
                'bp_code' => $originalArrival->bp_code,
                'driver_name' => $originalArrival->driver_name,
                'vehicle_plate' => $originalArrival->vehicle_plate,
                'schedule_id' => $schedule->id,
                'related_arrival_id' => $originalArrival->id,
                'status' => 'pending',
            ]);

            // Don't mark as partial here - delivery_compliance will be updated by scheduled task
            // based on whether the original arrival was delivered on time or not
            // Partial delivery should only be set if quantity is incomplete, not because of additional schedule

            // Don't update delivery_compliance here - it should be updated by nightly worker
            $duplicate->save();
        }
    }

    /**
     * Get schedule statistics
     */
    public function getStatistics()
    {
        $totalSchedules = ArrivalSchedule::count();
        $regularSchedules = ArrivalSchedule::regular()->count();
        $additionalSchedules = ArrivalSchedule::additional()->count();
        $activeSchedules = ArrivalSchedule::whereHas('arrivalTransactions')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_schedules' => $totalSchedules,
                'regular_schedules' => $regularSchedules,
                'additional_schedules' => $additionalSchedules,
                'active_schedules' => $activeSchedules,
            ]
        ]);
    }
}
