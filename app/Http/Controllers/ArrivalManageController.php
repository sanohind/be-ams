<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ArrivalSchedule;
use App\Models\ArrivalTransaction;
use App\Models\External\ScmBusinessPartner;
use App\Services\AuthService;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

        // Add supplier names from SCM database
        $schedules->each(function ($schedule) {
            $supplier = ScmBusinessPartner::where('bp_code', $schedule->bp_code)->first();
            $schedule->bp_name = $supplier?->bp_name ?? null;
        });

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

        // Check for duplicate schedule (same supplier, day, and arrival time for regular type)
        if ($request->arrival_type === 'regular') {
            $existingSchedule = ArrivalSchedule::where('bp_code', $request->bp_code)
                ->where('day_name', $request->day_name)
                ->where('arrival_time', $request->arrival_time)
                ->where('arrival_type', 'regular')
                ->first();
            
            if ($existingSchedule) {
                return response()->json([
                    'success' => false,
                    'message' => "Schedule already exists for {$request->bp_code} on {$request->day_name} at {$request->arrival_time}"
                ], 422);
            }
        }

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

        // Check for duplicate schedule when updating (exclude current record)
        if ($request->arrival_type === 'regular') {
            $existingSchedule = ArrivalSchedule::where('bp_code', $request->bp_code)
                ->where('day_name', $request->day_name)
                ->where('arrival_time', $request->arrival_time)
                ->where('arrival_type', 'regular')
                ->where('id', '!=', $id) // Exclude current record
                ->first();
            
            if ($existingSchedule) {
                return response()->json([
                    'success' => false,
                    'message' => "Schedule already exists for {$request->bp_code} on {$request->day_name} at {$request->arrival_time}"
                ], 422);
            }
        }

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

    /**
     * Import arrival schedules from Excel file
     */
    public function importFromExcel(\Illuminate\Http\Request $request)
    {
        $user = $this->authService->getUserFromRequest($request);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet|max:5120', // max 5MB
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->getRealPath();
            
            // Load spreadsheet using PhpSpreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            $schedules = [];
            $errors = [];
            $debugInfo = [];
            $rowNumber = 0;

            // Iterate through rows
            foreach ($worksheet->getRowIterator() as $row) {
                $rowNumber++;
                
                // Debug: log first 15 rows
                if ($rowNumber <= 15) {
                    $rowData = [
                        'B' => $worksheet->getCell('B' . $rowNumber)->getValue(),
                        'C' => $worksheet->getCell('C' . $rowNumber)->getValue(),
                        'D' => $worksheet->getCell('D' . $rowNumber)->getValue(),
                        'E' => $worksheet->getCell('E' . $rowNumber)->getValue(),
                        'F' => $worksheet->getCell('F' . $rowNumber)->getValue(),
                        'G' => $worksheet->getCell('G' . $rowNumber)->getValue(),
                    ];
                    $debugInfo[] = "Row {$rowNumber}: " . json_encode($rowData);
                }
                
                // Skip header rows (rows 1-4, data starts from row 5)
                if ($rowNumber <= 4) {
                    continue;
                }

                try {
                    // Extract columns from Excel:
                    // Column B: No.
                    // Column C: Supplier Code
                    // Column D: Day
                    // Column E: Arrival Time
                    // Column F: Departure Time
                    // Column G: Dock
                    
                    $bpCode = trim((string)$worksheet->getCell('C' . $rowNumber)->getValue());
                    $dayName = trim((string)$worksheet->getCell('D' . $rowNumber)->getValue());
                    $arrivalTime = trim((string)$worksheet->getCell('E' . $rowNumber)->getValue());
                    $departureTime = trim((string)$worksheet->getCell('F' . $rowNumber)->getValue());
                    $dock = trim((string)$worksheet->getCell('G' . $rowNumber)->getValue());

                    // Skip empty rows
                    if (empty($bpCode) && empty($dayName) && empty($arrivalTime) && empty($dock)) {
                        continue;
                    }

                    // Validate required fields
                    if (empty($bpCode) || empty($dayName) || empty($arrivalTime) || empty($dock)) {
                        $errors[] = "Row {$rowNumber}: Missing required fields (BP: '{$bpCode}', Day: '{$dayName}', Time: '{$arrivalTime}', Dock: '{$dock}')";
                        continue;
                    }

                    // Transform day name (Indonesian to English)
                    $transformedDay = $this->transformDayName($dayName);

                    // Validate day name
                    $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    if (!in_array($transformedDay, $validDays)) {
                        $errors[] = "Row {$rowNumber}: Invalid day name '{$dayName}'";
                        continue;
                    }

                    // Format time
                    $formattedArrivalTime = $this->formatTime($arrivalTime);
                    $formattedDepartureTime = !empty($departureTime) ? $this->formatTime($departureTime) : null;

                    // Validate time format
                    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $formattedArrivalTime)) {
                        $errors[] = "Row {$rowNumber}: Invalid arrival time format '{$arrivalTime}'";
                        continue;
                    }

                    if ($formattedDepartureTime && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $formattedDepartureTime)) {
                        $errors[] = "Row {$rowNumber}: Invalid departure time format '{$departureTime}'";
                        continue;
                    }

                    // Check for duplicate (same supplier, day, and arrival time)
                    $existingSchedule = ArrivalSchedule::where('bp_code', $bpCode)
                        ->where('day_name', $transformedDay)
                        ->where('arrival_time', $formattedArrivalTime)
                        ->where('arrival_type', 'regular')
                        ->first();
                    
                    if ($existingSchedule) {
                        $errors[] = "Row {$rowNumber}: Duplicate schedule - {$bpCode} on {$transformedDay} at {$formattedArrivalTime} already exists";
                        continue;
                    }

                    $schedules[] = [
                        'bp_code' => $bpCode,
                        'day_name' => $transformedDay,
                        'arrival_type' => 'regular', // Always regular for Excel import
                        'arrival_time' => $formattedArrivalTime,
                        'departure_time' => $formattedDepartureTime,
                        'dock' => $dock,
                        'created_by' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: {$e->getMessage()}";
                }
            }

            if (empty($schedules)) {
                // Check if all errors are duplicates
                $duplicateCount = count(array_filter($errors, function($error) {
                    return strpos($error, 'Duplicate schedule') !== false;
                }));
                
                $message = 'No valid data found in the file';
                if ($duplicateCount > 0 && $duplicateCount === count($errors)) {
                    $message = "All {$duplicateCount} row(s) are duplicates - schedules already exist in the database";
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => $errors,
                    'debug' => $debugInfo
                ], 422);
            }

            // Batch insert schedules
            ArrivalSchedule::insert($schedules);

            return response()->json([
                'success' => true,
                'message' => 'Successfully imported ' . count($schedules) . ' schedule(s)',
                'data' => [
                    'imported_count' => count($schedules),
                    'error_count' => count($errors),
                    'errors' => $errors
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Transform day name from Indonesian or English to English lowercase
     */
    protected function transformDayName($day)
    {
        $dayLower = strtolower(trim($day));

        $dayMapping = [
            // English
            'monday' => 'monday',
            'tuesday' => 'tuesday',
            'wednesday' => 'wednesday',
            'thursday' => 'thursday',
            'friday' => 'friday',
            'saturday' => 'saturday',
            'sunday' => 'sunday',
            // Indonesian
            'senin' => 'monday',
            'selasa' => 'tuesday',
            'rabu' => 'wednesday',
            'kamis' => 'thursday',
            'jumat' => 'friday',
            'sabtu' => 'saturday',
            'minggu' => 'sunday',
        ];

        return $dayMapping[$dayLower] ?? $dayLower;
    }

    /**
     * Format time to HH:MM format
     */
    protected function formatTime($time)
    {
        $time = trim($time);

        // If time is a number (Excel time format), convert it
        if (is_numeric($time)) {
            $hours = floor($time * 24);
            $minutes = floor(($time * 24 - $hours) * 60);
            return sprintf('%02d:%02d', $hours, $minutes);
        }

        // If it's already a string, just ensure HH:MM format
        $parts = explode(':', $time);
        if (count($parts) >= 2) {
            return sprintf('%02d:%02d', intval($parts[0]), intval($parts[1]));
        }

        return $time;
    }
}
