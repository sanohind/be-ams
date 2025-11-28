<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ArrivalTransaction;
use Carbon\Carbon;

class UpdateDeliveryCompliance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ams:update-delivery-compliance {--date= : Specific date to update (YYYY-MM-DD), defaults to today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update delivery compliance status for arrival transactions based on delivery timeline';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        
        $this->info("Updating delivery compliance for date: {$date->toDateString()}");

        // Get all regular arrivals for the date that haven't been delivered
        $arrivals = ArrivalTransaction::where('arrival_type', 'regular')
            ->whereDate('plan_delivery_date', $date)
            ->get();

        $updated = 0;
        $noShow = 0;
        $delay = 0;
        $onCommitment = 0;

        foreach ($arrivals as $arrival) {
            $originalCompliance = $arrival->delivery_compliance;
            
            // Check if arrival has been delivered (has warehouse check-in or completed)
            $hasDelivery = !empty($arrival->warehouse_checkin_time) || !empty($arrival->completed_at);
            
            // Check if there's an additional arrival for this DN
            $hasAdditional = ArrivalTransaction::where('arrival_type', 'additional')
                ->where('related_arrival_id', $arrival->id)
                ->whereNotNull('warehouse_checkin_time')
                ->exists();

            if (!$hasDelivery && !$hasAdditional) {
                // No delivery at all - check if it's past or at the plan date (command runs end-of-day)
                if ($date->greaterThanOrEqualTo(Carbon::parse($arrival->plan_delivery_date))) {
                    // Past delivery date and no delivery - mark as no_show
                    $arrival->markAsNoShow();
                    $arrival->save();
                    $updated++;
                    $noShow++;
                }
                continue;
            } elseif ($hasAdditional && !$hasDelivery) {
                // Original didn't come on scheduled date, but additional came later
                // This means delivery was delayed (delivered after scheduled date)
                $arrival->applyComplianceStatus(ArrivalTransaction::DELIVERY_COMPLIANCE_DELAY);
                $arrival->save();
                $delay++;
            } else {
                // Has delivery on scheduled date - check if on time or delay based on timeline
                $arrival->refreshComplianceFromTimeline();
                if ($arrival->delivery_compliance === ArrivalTransaction::DELIVERY_COMPLIANCE_ON_COMMITMENT) {
                    $onCommitment++;
                } elseif ($arrival->delivery_compliance === ArrivalTransaction::DELIVERY_COMPLIANCE_DELAY) {
                    $delay++;
                }
            }

            if ($arrival->delivery_compliance !== $originalCompliance) {
                $arrival->save();
                $updated++;
            }
        }

        // Also update additional arrivals compliance
        $additionalArrivals = ArrivalTransaction::where('arrival_type', 'additional')
            ->whereHas('schedule', function ($query) use ($date) {
                $query->whereDate('schedule_date', $date);
            })
            ->get();

        foreach ($additionalArrivals as $arrival) {
            $originalCompliance = $arrival->delivery_compliance;
            $arrival->refreshComplianceFromTimeline();
            
            if ($arrival->delivery_compliance !== $originalCompliance) {
                $arrival->save();
                $updated++;
            }

            // If this additional arrival is linked to a regular DN that was marked no_show,
            // convert that regular DN to delay because it was delivered late via additional.
            if ($arrival->related_arrival_id && $arrival->warehouse_checkin_time) {
                $related = ArrivalTransaction::find($arrival->related_arrival_id);
                if ($related && in_array($related->delivery_compliance, [
                    ArrivalTransaction::DELIVERY_COMPLIANCE_NO_SHOW,
                    ArrivalTransaction::DELIVERY_COMPLIANCE_INCOMPLETE
                ], true)) {
                    $related->applyComplianceStatus(ArrivalTransaction::DELIVERY_COMPLIANCE_DELAY);
                    $related->save();
                    $updated++;
                    $delay++;
                }
            }
        }

        $this->info("Updated {$updated} arrival(s)");
        $this->info("  - No Show: {$noShow}");
        $this->info("  - Delay: {$delay}");
        $this->info("  - On Commitment: {$onCommitment}");

        return 0;
    }
}

