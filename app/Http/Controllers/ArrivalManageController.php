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
            'date' => 'required|date',
            'bp_code' => 'nullable|string',
        ]);

        $query = ArrivalTransaction::regular()
            ->where('plan_delivery_date', $request->date)
            ->whereNull('schedule_id');

        if ($request->bp_code) {
            $query->where('bp_code', $request->bp_code);
        }

        $arrivals = $query->get();

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
            ArrivalTransaction::create([
                'dn_number' => $originalArrival->dn_number,
                'po_number' => $originalArrival->po_number,
                'arrival_type' => 'additional',
                'plan_delivery_date' => $schedule->schedule_date,
                'plan_delivery_time' => $originalArrival->plan_delivery_time,
                'bp_code' => $originalArrival->bp_code,
                'driver_name' => $originalArrival->driver_name,
                'vehicle_plate' => $originalArrival->vehicle_plate,
                'schedule_id' => $schedule->id,
                'status' => 'pending',
            ]);
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
