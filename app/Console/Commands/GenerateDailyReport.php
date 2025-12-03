<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DailyReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:generate-daily-report {--date= : Specific date to generate report for (Y-m-d format)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate daily arrival report with PDF';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        
        $this->info("Generating daily report for: {$date->toDateString()}");

        try {
            // Check if report already exists
            $existingReport = DailyReport::forDate($date->toDateString())->first();
            
            if ($existingReport) {
                $this->warn("Daily report for {$date->toDateString()} already exists. Skipping...");
                return 0;
            }

            // Generate report with PDF
            $report = DailyReport::generateForDate($date->toDateString());

            $this->info("✓ Daily report generated successfully");
            $this->line("  - Total suppliers: {$report->total_suppliers}");
            $this->line("  - Total arrivals: {$report->total_arrivals}");
            $this->line("  - On time: {$report->total_on_time}");
            $this->line("  - Delay: {$report->total_delay}");
            $this->line("  - Advance: {$report->total_advance}");
            if ($report->file_path) {
                $this->line("  - PDF file: {$report->file_path}");
            }

        } catch (\Exception $e) {
            $this->error('Failed to generate daily report: ' . $e->getMessage());
            Log::error('Daily report generation failed: ' . $e->getMessage(), [
                'date' => $date->toDateString(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }

        return 0;
    }
}
