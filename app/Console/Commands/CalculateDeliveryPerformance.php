<?php

namespace App\Console\Commands;

use App\Services\DeliveryPerformanceService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CalculateDeliveryPerformance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:calculate-delivery-performance
                            {--month= : Month to calculate (1-12), default is previous month}
                            {--year= : Year to calculate, default is current year}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate delivery performance for all suppliers in a specific period';

    /**
     * Execute the console command.
     */
    public function handle(DeliveryPerformanceService $service)
    {
        $startTime = microtime(true);

        // Get month and year from options or use previous month
        $month = $this->option('month');
        $year = $this->option('year');

        if (!$month || !$year) {
            $now = Carbon::now('Asia/Jakarta');
            $previousMonth = $now->copy()->subMonth();
            $month = $month ?? $previousMonth->month;
            $year = $year ?? $previousMonth->year;
        }

        // Validate month
        if ($month < 1 || $month > 12) {
            $this->error('Month must be between 1 and 12');
            return 1;
        }

        $this->info("Calculating delivery performance for {$month}/{$year}...");
        $this->newLine();

        try {
            // Calculate performance
            $results = $service->calculatePerformance($month, $year);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Display results
            $this->info("✓ Calculation completed successfully");
            $this->info("Total suppliers calculated: " . count($results));
            $this->info("Execution time: {$duration}s");
            $this->newLine();

            // Display top 5 performers
            $this->displayTopPerformers($month, $year);

            return 0;
        } catch (\Exception $e) {
            $this->error("Error during calculation: {$e->getMessage()}");
            \Log::error("CalculateDeliveryPerformance error: {$e->getMessage()}", [
                'month' => $month,
                'year' => $year,
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * Display top 5 performers
     */
    private function displayTopPerformers($month, $year)
    {
        $service = app(DeliveryPerformanceService::class);
        $topPerformers = $service->getPerformanceList($month, $year, 5);

        if ($topPerformers->isEmpty()) {
            $this->warn('No performance data found for this period');
            return;
        }

        $this->info('Top 5 Performers:');
        $this->newLine();

        $headers = ['Rank', 'Supplier Code', 'Score', 'Grade', 'Category'];
        $rows = [];

        foreach ($topPerformers as $performance) {
            $rows[] = [
                $performance->ranking,
                $performance->bp_code,
                $performance->final_score,
                $performance->performance_grade,
                strtoupper($performance->category),
            ];
        }

        $this->table($headers, $rows);
    }
}
