<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use Carbon\Carbon;

class UpdateArrivalStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:update-arrival-status {--date= : Specific date to update (YYYY-MM-DD), defaults to today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update arrival status (ontime/delay/advance) for arrival transactions based on actual arrival time vs schedule';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        
        $this->info("Updating arrival status for date: {$date->toDateString()}");

        // Update regular arrivals
        $regularArrivals = ArrivalTransaction::where('arrival_type', 'regular')
            ->whereDate('plan_delivery_date', $date)
            ->with('schedule')
            ->get();

        $updated = 0;
        $ontime = 0;
        $delay = 0;
        $advance = 0;

        foreach ($regularArrivals as $arrival) {
            $status = $this->calculateArrivalStatus($arrival);
            
            if ($arrival->status !== $status) {
                $arrival->status = $status;
                $arrival->save();
                $updated++;
                
                switch ($status) {
                    case 'on_time':
                        $ontime++;
                        break;
                    case 'delay':
                        $delay++;
                        break;
                    case 'advance':
                        $advance++;
                        break;
                }
            }
        }

        // Update additional arrivals
        $additionalArrivals = ArrivalTransaction::where('arrival_type', 'additional')
            ->whereHas('schedule', function ($query) use ($date) {
                $query->whereDate('schedule_date', $date);
            })
            ->with('schedule')
            ->get();

        foreach ($additionalArrivals as $arrival) {
            $status = $this->calculateArrivalStatus($arrival);
            
            if ($arrival->status !== $status) {
                $arrival->status = $status;
                $arrival->save();
                $updated++;
                
                switch ($status) {
                    case 'on_time':
                        $ontime++;
                        break;
                    case 'delay':
                        $delay++;
                        break;
                    case 'advance':
                        $advance++;
                        break;
                }
            }
        }

        $this->info("Updated {$updated} arrival(s)");
        $this->info("  - On Time: {$ontime}");
        $this->info("  - Delay: {$delay}");
        $this->info("  - Advance: {$advance}");

        return 0;
    }

    /**
     * Calculate arrival status based on actual time vs schedule
     */
    protected function calculateArrivalStatus($arrival)
    {
        $schedule = $arrival->schedule;
        
        if (!$schedule || !$schedule->arrival_time) {
            return 'pending';
        }

        // Get actual arrival time (prefer warehouse check-in, fallback to security check-in)
        $actualTime = $arrival->warehouse_checkin_time;
        if (!$actualTime) {
            return 'pending';
        }

        // Determine scheduled date
        if ($arrival->arrival_type === 'additional' && $schedule && $schedule->schedule_date) {
            $scheduledDate = Carbon::parse($schedule->schedule_date, config('app.timezone'));
        } else {
            $scheduledDate = $arrival->plan_delivery_date
                ? Carbon::parse($arrival->plan_delivery_date, config('app.timezone'))
                : ($arrival->warehouse_checkin_time
                    ? Carbon::parse($arrival->warehouse_checkin_time, config('app.timezone'))
                    : null);
        }

        if (!$scheduledDate) {
            return 'pending';
        }

        // Determine scheduled time (prefer schedule arrival_time, fallback to plan_delivery_time)
        $scheduledTimeString = null;
        if ($schedule && $schedule->arrival_time) {
            $scheduledTimeString = $schedule->arrival_time;
        } elseif ($arrival->plan_delivery_time) {
            $scheduledTimeString = $arrival->plan_delivery_time;
        }

        if (!$scheduledTimeString) {
            return 'pending';
        }

        $scheduledTimeString = trim($scheduledTimeString);
        $timezone = config('app.timezone');

        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $scheduledTimeString)) {
            // Time only (HH:mm or HH:mm:ss)
            $scheduledTime = Carbon::parse(
                $scheduledDate->format('Y-m-d') . ' ' . $scheduledTimeString,
                $timezone
            );
        } elseif (preg_match('/\d{4}-\d{2}-\d{2}/', $scheduledTimeString)) {
            // Contains date portion, parse then align date with scheduledDate
            $scheduledTime = Carbon::parse($scheduledTimeString, $timezone)
                ->setDate(
                    $scheduledDate->year,
                    $scheduledDate->month,
                    $scheduledDate->day
                );
        } else {
            // Fallback: append to scheduled date
            $scheduledTime = Carbon::parse(
                $scheduledDate->format('Y-m-d') . ' ' . $scheduledTimeString,
                $timezone
            );
        }

        $actualTime = Carbon::parse($actualTime, $timezone);
        
        if ($actualTime->lessThan($scheduledTime)) {
            return 'advance';
        }

        if ($actualTime->greaterThanOrEqualTo($scheduledTime->copy()->addHour())) {
            return 'delay';
        }

        return 'on_time';
    }
}

