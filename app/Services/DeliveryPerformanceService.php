<?php

namespace App\Services;

use App\Models\DeliveryPerformance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DeliveryPerformanceService
{
    /**
     * Calculate delivery performance for a specific month and year
     * If month/year not provided, calculate for previous month
     */
    public function calculatePerformance($month = null, $year = null)
    {
        if (is_null($month) || is_null($year)) {
            $now = Carbon::now('Asia/Jakarta');
            $previousMonth = $now->copy()->subMonth();
            $month = $previousMonth->month;
            $year = $previousMonth->year;
        }

        $startDate = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Get all suppliers that have DN in this period
        $suppliers = $this->getSuppliersDNInPeriod($startDate, $endDate);

        $results = [];
        foreach ($suppliers as $supplier) {
            try {
                $performance = $this->calculateSupplierPerformance(
                    $supplier['bp_code'],
                    $month,
                    $year,
                    $startDate,
                    $endDate
                );
                $results[] = $performance;
            } catch (\Exception $e) {
                \Log::error("Error calculating performance for supplier {$supplier['bp_code']}: {$e->getMessage()}");
            }
        }

        // Update ranking and category
        $this->updateRankingAndCategory($month, $year);

        return $results;
    }

    /**
     * Get all suppliers that have DN in the specified period
     */
    private function getSuppliersDNInPeriod($startDate, $endDate)
    {
        return DB::connection('scm')
            ->table('dn_header')
            ->select('supplier_code as bp_code')
            ->distinct()
            ->whereBetween('plan_delivery_date', [$startDate, $endDate])
            ->get()
            ->toArray();
    }

    /**
     * Calculate performance for a specific supplier in a specific period
     */
    private function calculateSupplierPerformance($bpCode, $month, $year, $startDate, $endDate)
    {
        // Step 1: Calculate Fulfillment (Order Fulfillment)
        $fulfillmentData = $this->calculateFulfillment($bpCode, $startDate, $endDate);

        // Step 2: Calculate On-Time Delivery
        $deliveryData = $this->calculateOnTimeDelivery($bpCode, $startDate, $endDate);

        // Step 3: Calculate indexes
        $fulfillmentIndex = $this->getFulfillmentIndex($fulfillmentData['percentage']);
        $deliveryIndex = $deliveryData['total_index'];

        // Step 4: Calculate final score
        $totalIndex = $fulfillmentIndex + $deliveryIndex;
        $finalScore = max(0, 100 - $totalIndex);
        $performanceGrade = $this->getGrade($finalScore);

        // Step 5: Save to database
        $performance = DeliveryPerformance::updateOrCreate(
            [
                'bp_code' => $bpCode,
                'period_month' => $month,
                'period_year' => $year,
            ],
            [
                'total_dn_qty' => $fulfillmentData['total_dn_qty'],
                'total_receipt_qty' => $fulfillmentData['total_receipt_qty'],
                'fulfillment_percentage' => $fulfillmentData['percentage'],
                'fulfillment_index' => $fulfillmentIndex,
                'total_deliveries' => $deliveryData['total_deliveries'],
                'on_time_deliveries' => $deliveryData['on_time_deliveries'],
                'total_delay_days' => $deliveryData['total_delay_days'],
                'delivery_index' => $deliveryIndex,
                'total_index' => $totalIndex,
                'final_score' => $finalScore,
                'performance_grade' => $performanceGrade,
                'calculated_at' => now(),
            ]
        );

        return $performance;
    }

    /**
     * Calculate fulfillment data (Order Fulfillment)
     * Compares total DN quantity with actual scanned quantity
     */
    private function calculateFulfillment($bpCode, $startDate, $endDate)
    {
        // Get total DN quantity from SCM
        $totalDnQty = DB::connection('scm')
            ->table('dn_header')
            ->join('dn_detail', 'dn_header.no_dn', '=', 'dn_detail.no_dn')
            ->where('dn_header.supplier_code', $bpCode)
            ->whereBetween('dn_header.plan_delivery_date', [$startDate, $endDate])
            ->sum('dn_detail.dn_qty');

        // Get total receipt quantity from scanned items
        $totalReceiptQty = DB::table('scanned_items')
            ->join('arrival_transactions', 'scanned_items.arrival_id', '=', 'arrival_transactions.id')
            ->join('dn_header', 'scanned_items.dn_number', '=', 'dn_header.no_dn')
            ->where('dn_header.supplier_code', $bpCode)
            ->whereBetween('dn_header.plan_delivery_date', [$startDate, $endDate])
            ->sum('scanned_items.scanned_quantity');

        // Calculate percentage
        $percentage = $totalDnQty > 0 ? round(($totalReceiptQty / $totalDnQty) * 100, 2) : 0;

        return [
            'total_dn_qty' => $totalDnQty,
            'total_receipt_qty' => $totalReceiptQty,
            'percentage' => $percentage,
        ];
    }

    /**
     * Calculate on-time delivery data
     * Only considers arrival_type = 'regular'
     */
    private function calculateOnTimeDelivery($bpCode, $startDate, $endDate)
    {
        // Get all regular arrivals in this period
        $arrivals = DB::table('arrival_transactions')
            ->where('arrival_type', 'regular')
            ->where('bp_code', $bpCode)
            ->whereBetween('plan_delivery_date', [$startDate, $endDate])
            ->get();

        $totalDeliveries = $arrivals->count();
        $onTimeDeliveries = $arrivals->where('delivery_compliance', 'on_commitment')->count();
        $totalDelayDays = 0;
        $totalDeliveryIndex = 0;

        // Calculate delay days and index for delayed arrivals
        foreach ($arrivals->where('delivery_compliance', 'delay') as $arrival) {
            $delayDays = $this->calculateDelayDays($arrival->id);
            $totalDelayDays += $delayDays;
            $totalDeliveryIndex += $this->getDelayIndex($delayDays);
        }

        return [
            'total_deliveries' => $totalDeliveries,
            'on_time_deliveries' => $onTimeDeliveries,
            'total_delay_days' => $totalDelayDays,
            'total_index' => $totalDeliveryIndex,
        ];
    }

    /**
     * Calculate delay days for a specific arrival
     * Delay = schedule_date (from arrival_additional) - plan_delivery_date (from arrival_regular)
     */
    private function calculateDelayDays($arrivalId)
    {
        // Get the regular arrival
        $regularArrival = DB::table('arrival_transactions')
            ->find($arrivalId);

        if (!$regularArrival) {
            return 0;
        }

        // Get the related additional arrival
        $additionalArrival = DB::table('arrival_transactions')
            ->where('related_arrival_id', $arrivalId)
            ->where('arrival_type', 'additional')
            ->first();

        if (!$additionalArrival || !$additionalArrival->schedule_id) {
            return 0;
        }

        // Get schedule date from arrival_schedule
        $schedule = DB::table('arrival_schedule')
            ->find($additionalArrival->schedule_id);

        if (!$schedule) {
            return 0;
        }

        // Calculate delay days
        $planDate = Carbon::parse($regularArrival->plan_delivery_date);
        $scheduleDate = Carbon::parse($schedule->schedule_date);

        return max(0, $scheduleDate->diffInDays($planDate));
    }

    /**
     * Get fulfillment index based on percentage
     * 95-100% → 0, 85-94% → 2, 75-84% → 4, 65-74% → 6, <64% → 8
     */
    private function getFulfillmentIndex($percentage)
    {
        if ($percentage >= 95) {
            return 0;
        } elseif ($percentage >= 85) {
            return 2;
        } elseif ($percentage >= 75) {
            return 4;
        } elseif ($percentage >= 65) {
            return 6;
        } else {
            return 8;
        }
    }

    /**
     * Get delay index based on delay days
     * 1 day → 2, 2 days → 4, 3 days → 6, >3 days → 10
     */
    private function getDelayIndex($delayDays)
    {
        if ($delayDays === 1) {
            return 2;
        } elseif ($delayDays === 2) {
            return 4;
        } elseif ($delayDays === 3) {
            return 6;
        } else {
            return 10;
        }
    }

    /**
     * Get performance grade based on final score
     * 100 → A, 80-99 → B, 60-79 → C, <60 → D
     */
    private function getGrade($score)
    {
        if ($score >= 100) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 60) {
            return 'C';
        } else {
            return 'D';
        }
    }

    /**
     * Update ranking and category for all suppliers in a period
     */
    private function updateRankingAndCategory($month, $year)
    {
        // Get all performances for this period, ordered by score DESC
        $performances = DeliveryPerformance::forPeriod($year, $month)
            ->orderedByScore()
            ->get();

        $ranking = 1;
        foreach ($performances as $performance) {
            $performance->update([
                'ranking' => $ranking,
                'category' => $this->getCategoryFromScore($performance->final_score),
            ]);
            $ranking++;
        }
    }

    /**
     * Get category based on final score
     */
    private function getCategoryFromScore($score)
    {
        if ($score >= 90) {
            return 'best';
        } elseif ($score >= 70) {
            return 'medium';
        } else {
            return 'worst';
        }
    }

    /**
     * Get delivery performance list for a specific period
     */
    public function getPerformanceList($month, $year, $limit = null)
    {
        $query = DeliveryPerformance::forPeriod($year, $month)
            ->orderedByScore();

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get delivery performance detail for a specific supplier
     */
    public function getPerformanceDetail($bpCode, $month, $year)
    {
        return DeliveryPerformance::forPeriod($year, $month)
            ->forSupplier($bpCode)
            ->first();
    }
}
