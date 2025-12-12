<?php

namespace App\Http\Controllers;

use App\Models\DeliveryPerformance;
use App\Services\DeliveryPerformanceService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DeliveryPerformanceController extends Controller
{
    protected $service;

    public function __construct(DeliveryPerformanceService $service)
    {
        $this->service = $service;
    }

    /**
     * Get delivery performance list for a specific period
     * Query params: month, year, limit, category, grade
     */
    public function index(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $limit = $request->query('limit');
        $category = $request->query('category');
        $grade = $request->query('grade');

        // Default to current month if not provided
        if (!$month || !$year) {
            $now = Carbon::now('Asia/Jakarta');
            $month = $month ?? $now->month;
            $year = $year ?? $now->year;
        }

        // Validate month and year
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month or year',
            ], 400);
        }

        try {
            $query = DeliveryPerformance::forPeriod($year, $month)
                ->orderedByScore();

            // Apply filters
            if ($category) {
                $query->where('category', $category);
            }

            if ($grade) {
                $query->withGrade($grade);
            }

            // Apply limit
            if ($limit && is_numeric($limit) && $limit > 0) {
                $query->limit($limit);
            }

            $performances = $query->get();

            return response()->json([
                'success' => true,
                'data' => $performances,
                'period' => [
                    'month' => $month,
                    'year' => $year,
                ],
                'total' => $performances->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching delivery performance list: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching performance data',
            ], 500);
        }
    }

    /**
     * Get delivery performance detail for a specific supplier
     */
    public function show(Request $request, $bpCode)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        // Default to current month if not provided
        if (!$month || !$year) {
            $now = Carbon::now('Asia/Jakarta');
            $month = $month ?? $now->month;
            $year = $year ?? $now->year;
        }

        // Validate month and year
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month or year',
            ], 400);
        }

        try {
            $performance = $this->service->getPerformanceDetail($bpCode, $month, $year);

            if (!$performance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Performance data not found for this supplier and period',
                ], 404);
            }

            // Get supplier name from SCM
            $supplier = \DB::connection('scm')
                ->table('business_partner')
                ->where('bp_code', $bpCode)
                ->select('bp_code', 'bp_name')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'performance' => $performance,
                    'supplier' => $supplier,
                ],
                'period' => [
                    'month' => $month,
                    'year' => $year,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching delivery performance detail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching performance data',
            ], 500);
        }
    }

    /**
     * Get top performers for a specific period
     */
    public function topPerformers(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $limit = $request->query('limit', 10);

        // Default to current month if not provided
        if (!$month || !$year) {
            $now = Carbon::now('Asia/Jakarta');
            $month = $month ?? $now->month;
            $year = $year ?? $now->year;
        }

        // Validate month and year
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month or year',
            ], 400);
        }

        try {
            $performers = $this->service->getPerformanceList($month, $year, $limit);

            return response()->json([
                'success' => true,
                'data' => $performers,
                'period' => [
                    'month' => $month,
                    'year' => $year,
                ],
                'total' => $performers->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching top performers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching performance data',
            ], 500);
        }
    }

    /**
     * Get performance statistics for a specific period
     */
    public function statistics(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        // Default to current month if not provided
        if (!$month || !$year) {
            $now = Carbon::now('Asia/Jakarta');
            $month = $month ?? $now->month;
            $year = $year ?? $now->year;
        }

        // Validate month and year
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month or year',
            ], 400);
        }

        try {
            $performances = DeliveryPerformance::forPeriod($year, $month)->get();

            if ($performances->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No performance data found for this period',
                ], 404);
            }

            // Calculate statistics
            $stats = [
                'total_suppliers' => $performances->count(),
                'average_score' => round($performances->avg('final_score'), 2),
                'highest_score' => $performances->max('final_score'),
                'lowest_score' => $performances->min('final_score'),
                'grade_distribution' => [
                    'A' => $performances->where('performance_grade', 'A')->count(),
                    'B' => $performances->where('performance_grade', 'B')->count(),
                    'C' => $performances->where('performance_grade', 'C')->count(),
                    'D' => $performances->where('performance_grade', 'D')->count(),
                ],
                'category_distribution' => [
                    'best' => $performances->where('category', 'best')->count(),
                    'medium' => $performances->where('category', 'medium')->count(),
                    'worst' => $performances->where('category', 'worst')->count(),
                ],
                'average_fulfillment_percentage' => round($performances->avg('fulfillment_percentage'), 2),
                'average_on_time_percentage' => round(
                    $performances->map(function ($p) {
                        return $p->total_deliveries > 0 
                            ? ($p->on_time_deliveries / $p->total_deliveries) * 100 
                            : 0;
                    })->avg(),
                    2
                ),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'period' => [
                    'month' => $month,
                    'year' => $year,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching performance statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
            ], 500);
        }
    }

    /**
     * Manually trigger performance calculation for a specific period
     * Only for testing/admin purposes
     */
    public function calculate(Request $request)
    {
        $month = $request->input('month');
        $year = $request->input('year');

        // Validate input
        if (!$month || !$year || $month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid month or year',
            ], 400);
        }

        try {
            $startTime = microtime(true);
            $results = $this->service->calculatePerformance($month, $year);
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            return response()->json([
                'success' => true,
                'message' => 'Performance calculation completed',
                'data' => [
                    'total_suppliers' => count($results),
                    'execution_time' => $duration . 's',
                ],
                'period' => [
                    'month' => $month,
                    'year' => $year,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error calculating delivery performance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error during calculation: ' . $e->getMessage(),
            ], 500);
        }
    }
}
