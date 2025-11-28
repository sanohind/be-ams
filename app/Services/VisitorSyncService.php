<?php

namespace App\Services;

use App\Models\ArrivalTransaction;
use App\Models\External\Visitor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class VisitorSyncService
{
    /**
     * Sync security checkout time from Visitor database to Arrival Transactions.
     *
     * @param  Carbon|null  $forDate  Optional date filter (YYYY-MM-DD)
     * @return array{
     *     success: bool,
     *     processed: int,
     *     updated: int,
     *     skipped: int,
     *     unmatched: int,
     * }
     */
    public function syncSecurityCheckout(?Carbon $forDate = null): array
    {
        $query = ArrivalTransaction::query()
            ->whereNotNull('warehouse_checkout_time')
            ->whereNull('security_checkout_time');

        if ($forDate) {
            $query->whereDate('plan_delivery_date', $forDate->toDateString());
        }

        /** @var Collection<int, ArrivalTransaction> $arrivals */
        $arrivals = $query->get();

        $stats = [
            'success' => true,
            'processed' => $arrivals->count(),
            'updated' => 0,
            'skipped' => 0,
            'unmatched' => 0,
        ];

        foreach ($arrivals as $arrival) {
            $visitor = $this->findVisitorForArrival($arrival);

            if (!$visitor) {
                $stats['unmatched']++;
                continue;
            }

            if (!$visitor->visitor_checkout) {
                $stats['skipped']++;
                continue;
            }

            $arrival->security_checkout_time = $visitor->visitor_checkout;

            if (is_null($arrival->visitor_id)) {
                $arrival->visitor_id = $visitor->visitor_id;
            }

            $arrival->save();
            $arrival->calculateSecurityDuration();

            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Try to find matching visitor record for given arrival.
     */
    protected function findVisitorForArrival(ArrivalTransaction $arrival): ?Visitor
    {
        // 1. Attempt match by stored visitor_id
        if (!is_null($arrival->visitor_id)) {
            $visitor = Visitor::where('visitor_id', $arrival->visitor_id)
                ->whereNotNull('visitor_checkout')
                ->first();

            if ($visitor && $this->isVisitorMatch($visitor, $arrival)) {
                return $visitor;
            }
        }

        // 2. Match by driver name & vehicle plate (same logic as check-in sync)
        if (!$arrival->driver_name || !$arrival->vehicle_plate) {
            return null;
        }

        $referenceDate = $this->resolveReferenceDate($arrival);

        $visitorQuery = Visitor::where('visitor_name', $arrival->driver_name)
            ->where('visitor_vehicle', $arrival->vehicle_plate)
            ->whereNotNull('visitor_checkout');

        if ($referenceDate) {
            $visitorQuery->whereDate('visitor_date', $referenceDate->toDateString());
        }

        if ($arrival->plan_delivery_time) {
            $time = Carbon::parse($arrival->plan_delivery_time)->format('H:i:s');
            $visitorQuery->whereTime('plan_delivery_time', $time);
        }

        $visitor = $visitorQuery->orderBy('visitor_checkout', 'desc')->first();

        if ($visitor && $this->isVisitorMatch($visitor, $arrival)) {
            return $visitor;
        }

        // As a fallback, try relaxed matching within +/- 1 day if nothing found
        if ($referenceDate) {
            $alternateQuery = Visitor::where('visitor_name', $arrival->driver_name)
                ->where('visitor_vehicle', $arrival->vehicle_plate)
                ->whereNotNull('visitor_checkout')
                ->whereBetween('visitor_date', [
                    $referenceDate->copy()->subDay()->toDateString(),
                    $referenceDate->copy()->addDay()->toDateString(),
                ]);

            if ($arrival->plan_delivery_time) {
                $time = Carbon::parse($arrival->plan_delivery_time)->format('H:i:s');
                $alternateQuery->whereTime('plan_delivery_time', $time);
            }

            $alternateVisitor = $alternateQuery
                ->orderBy('visitor_checkout', 'desc')
                ->first();

            if ($alternateVisitor && $this->isVisitorMatch($alternateVisitor, $arrival)) {
                return $alternateVisitor;
            }
        }

        return null;
    }

    /**
     * Determine the best date to use for visitor lookup.
     */
    protected function resolveReferenceDate(ArrivalTransaction $arrival): ?Carbon
    {
        if ($arrival->warehouse_checkout_time) {
            return Carbon::parse($arrival->warehouse_checkout_time);
        }

        if ($arrival->warehouse_checkin_time) {
            return Carbon::parse($arrival->warehouse_checkin_time);
        }

        if ($arrival->plan_delivery_date) {
            return Carbon::parse($arrival->plan_delivery_date);
        }

        return null;
    }

    /**
     * Ensure visitor data matches arrival context to avoid incorrect associations.
     */
    protected function isVisitorMatch(Visitor $visitor, ArrivalTransaction $arrival): bool
    {
        $visitorName = $this->normalizeString($visitor->visitor_name);
        $visitorVehicle = $this->normalizeVehicle($visitor->visitor_vehicle);

        $driverName = $this->normalizeString($arrival->driver_name);
        $vehiclePlate = $this->normalizeVehicle($arrival->vehicle_plate);

        if ($driverName && $visitorName && $driverName !== $visitorName) {
            return false;
        }

        if ($vehiclePlate && $visitorVehicle && $vehiclePlate !== $visitorVehicle) {
            return false;
        }

        $referenceDate = $this->resolveReferenceDate($arrival);

        if ($referenceDate && $visitor->visitor_date) {
            $visitorDate = Carbon::parse($visitor->visitor_date);

            if ($visitorDate->diffInDays($referenceDate, false) > 1) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    protected function normalizeVehicle(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', strtoupper($value));

        return $normalized === '' ? null : $normalized;
    }
}

