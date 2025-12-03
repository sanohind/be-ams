<?php

namespace App\Services;

use App\Models\ArrivalTransaction;
use App\Models\External\Visitor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VisitorSyncService
{
    /**
     * Sync security check-in time from Visitor database to Arrival Transactions.
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
    public function syncSecurityCheckin(?Carbon $forDate = null): array
    {
        $targetDate = $this->resolveTargetDate($forDate);

        Log::info('VisitorSyncService::syncSecurityCheckin started', [
            'date' => $targetDate->toDateString(),
        ]);

        $stats = $this->syncFromVisitorRecords($targetDate, false);

        Log::info('VisitorSyncService::syncSecurityCheckin completed', [
            'date' => $targetDate->toDateString(),
            'stats' => $stats,
        ]);

        return $stats;
    }

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
        $targetDate = $this->resolveTargetDate($forDate);

        Log::info('VisitorSyncService::syncSecurityCheckout started', [
            'date' => $targetDate->toDateString(),
        ]);

        $stats = $this->syncFromVisitorRecords($targetDate, true);

        Log::info('VisitorSyncService::syncSecurityCheckout completed', [
            'date' => $targetDate->toDateString(),
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Sync visitor records (check-in or checkout) for the given date.
     */
    protected function syncFromVisitorRecords(Carbon $date, bool $forCheckout = false): array
    {
        $dateString = $date->toDateString();

        $visitorQuery = Visitor::forDate($dateString);

        if ($forCheckout) {
            $visitorQuery->whereNotNull('visitor_checkout');
        } else {
            $visitorQuery->whereNotNull('visitor_checkin');
        }

        /** @var Collection<int, Visitor> $visitors */
        $visitors = $visitorQuery->orderBy($forCheckout ? 'visitor_checkout' : 'visitor_checkin')->get();

        $stats = [
            'success' => true,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unmatched' => 0,
        ];

        foreach ($visitors as $visitor) {
            if (!$visitor->bp_code || !$visitor->visitor_name || !$visitor->visitor_vehicle) {
                Log::debug('VisitorSyncService::syncFromVisitorRecords: Visitor missing required fields', [
                    'visitor_id' => $visitor->visitor_id,
                    'bp_code' => $visitor->bp_code,
                    'driver' => $visitor->visitor_name,
                    'vehicle' => $visitor->visitor_vehicle,
                ]);
                continue;
            }

            $arrivals = $this->matchArrivalsForVisitor(
                $dateString,
                $visitor,
                !$forCheckout,
                $forCheckout
            );

            if ($arrivals->isEmpty()) {
                $stats['unmatched']++;
                Log::debug('VisitorSyncService::syncFromVisitorRecords: No arrival matched for visitor', [
                    'visitor_id' => $visitor->visitor_id,
                    'bp_code' => $visitor->bp_code,
                    'driver' => $visitor->visitor_name,
                    'vehicle' => $visitor->visitor_vehicle,
                    'date' => $dateString,
                    'mode' => $forCheckout ? 'checkout' : 'checkin',
                ]);
                continue;
            }

            foreach ($arrivals as $arrival) {
                $stats['processed']++;
                $dirty = false;

                $visitorId = $visitor->getAttribute('visitor_id');

                if ($this->isVisitorIdEmpty($arrival->visitor_id) && $visitorId && $visitorId !== '0' && $visitorId !== 0) {
                    $arrival->visitor_id = (string) $visitorId;
                    $dirty = true;
                }

                if (!$forCheckout && is_null($arrival->security_checkin_time) && $visitor->visitor_checkin) {
                    $arrival->security_checkin_time = $visitor->visitor_checkin;
                    $dirty = true;
                }

                if ($forCheckout && is_null($arrival->security_checkout_time) && $visitor->visitor_checkout) {
                    $arrival->security_checkout_time = $visitor->visitor_checkout;
                    $dirty = true;
                }

                if (!$dirty) {
                    $stats['skipped']++;
                    continue;
                }

                if (!$arrival->save()) {
                    Log::error('VisitorSyncService::syncFromVisitorRecords: Failed to save arrival transaction', [
                        'arrival_id' => $arrival->id,
                    ]);
                    continue;
                }

                if ($forCheckout) {
                    $arrival->calculateSecurityDuration();
                }

                $stats['updated']++;
            }
        }

        return $stats;
    }

    /**
     * Find arrival transactions that match the given visitor info for the provided date.
     *
     * @return Collection<int, ArrivalTransaction>
     */
    protected function matchArrivalsForVisitor(string $date, Visitor $visitor, bool $needsCheckin, bool $needsCheckout): Collection
    {
        $query = $this->buildArrivalMatchQuery($date, $visitor, $needsCheckin, $needsCheckout, true);
        $arrivals = $query->get();

        if ($arrivals->isEmpty() && $visitor->plan_delivery_time) {
            $arrivals = $this->buildArrivalMatchQuery($date, $visitor, $needsCheckin, $needsCheckout, false)->get();
        }

        return $arrivals;
    }

    /**
     * Build an arrival query for a specific visitor.
     */
    protected function buildArrivalMatchQuery(string $date, Visitor $visitor, bool $needsCheckin, bool $needsCheckout, bool $includePlanTime)
    {
        $query = ArrivalTransaction::forDate($date)
            ->where('bp_code', $visitor->bp_code);

        if ($needsCheckin) {
            $query->whereNull('security_checkin_time');
        }

        if ($needsCheckout) {
            $query->whereNull('security_checkout_time');
        }

        $normalizedName = $this->normalizeString($visitor->visitor_name);
        if ($normalizedName) {
            $query->where(function ($subQuery) use ($visitor, $normalizedName) {
                $subQuery->where('driver_name', $visitor->visitor_name)
                    ->orWhereRaw('LOWER(TRIM(driver_name)) = ?', [$normalizedName]);
            });
        }

        $normalizedVehicle = $this->normalizeVehicle($visitor->visitor_vehicle);
        if ($normalizedVehicle) {
            $query->where(function ($subQuery) use ($visitor, $normalizedVehicle) {
                $subQuery->where('vehicle_plate', $visitor->visitor_vehicle)
                    ->orWhereRaw('UPPER(REPLACE(vehicle_plate, " ", "")) = ?', [$normalizedVehicle]);
            });
        }

        if ($includePlanTime && $visitor->plan_delivery_time) {
            $timeValue = $visitor->plan_delivery_time instanceof Carbon
                ? $visitor->plan_delivery_time->format('H:i:s')
                : Carbon::parse($visitor->plan_delivery_time)->format('H:i:s');

            $query->whereTime('plan_delivery_time', $timeValue);
        }

        return $query;
    }

    /**
     * Resolve the target date (defaults to today in Asia/Jakarta timezone).
     */
    protected function resolveTargetDate(?Carbon $forDate = null): Carbon
    {
        if ($forDate) {
            return $forDate->copy()->timezone('Asia/Jakarta')->startOfDay();
        }

        return Carbon::now('Asia/Jakarta')->startOfDay();
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

    /**
     * Check if visitor_id is empty or invalid (null, empty string, or '0')
     * This handles cases where old BIGINT column stored '0' for invalid values
     */
    protected function isVisitorIdEmpty($visitorId): bool
    {
        return is_null($visitorId) 
            || $visitorId === '' 
            || $visitorId === '0' 
            || $visitorId === 0;
    }
}

