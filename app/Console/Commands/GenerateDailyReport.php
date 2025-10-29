<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DailyReport;
use App\Models\ArrivalTransaction;
use Carbon\Carbon;

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
    protected $description = 'Generate daily arrival report';

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

            // Generate report
            $report = DailyReport::generateForDate($date->toDateString());

            $this->info("✓ Daily report generated successfully");
            $this->line("  - Total arrivals: {$report->total_arrivals}");
            $this->line("  - On time: {$report->total_on_time}");
            $this->line("  - Delay: {$report->total_delay}");
            $this->line("  - On time percentage: {$report->on_time_percentage}%");

        } catch (\Exception $e) {
            $this->error('Failed to generate daily report: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
