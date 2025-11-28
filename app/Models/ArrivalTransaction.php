<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ArrivalTransaction extends Model
{
    use HasFactory;

    protected $table = 'arrival_transactions';
    
    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Validate before creating to prevent duplicate regular arrivals
        static::creating(function ($arrival) {
            if ($arrival->arrival_type === 'regular') {
                $existing = static::where('dn_number', $arrival->dn_number)
                    ->where('po_number', $arrival->po_number)
                    ->where('arrival_type', 'regular')
                    ->where('id', '!=', $arrival->id ?? 0)
                    ->first();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'dn_number' => 'A regular arrival with this DN number and PO number already exists.',
                    ]);
                }
            }

            if (empty($arrival->delivery_compliance)) {
                $arrival->delivery_compliance = self::DELIVERY_COMPLIANCE_PENDING;
            }
        });
    }

    public const DELIVERY_COMPLIANCE_PENDING = 'pending';
    public const DELIVERY_COMPLIANCE_ON_COMMITMENT = 'on_commitment';
    public const DELIVERY_COMPLIANCE_DELAY = 'delay';
    public const DELIVERY_COMPLIANCE_NO_SHOW = 'no_show';
    public const DELIVERY_COMPLIANCE_PARTIAL = 'partial_delivery';
    public const DELIVERY_COMPLIANCE_INCOMPLETE = 'incomplete_qty';

    protected const COMPLIANCE_PRIORITIES = [
        self::DELIVERY_COMPLIANCE_PENDING => 0,
        self::DELIVERY_COMPLIANCE_ON_COMMITMENT => 1,
        self::DELIVERY_COMPLIANCE_INCOMPLETE => 2,
        self::DELIVERY_COMPLIANCE_PARTIAL => 3,
        self::DELIVERY_COMPLIANCE_DELAY => 4,
        self::DELIVERY_COMPLIANCE_NO_SHOW => 5,
    ];

    protected $fillable = [
        'dn_number',
        'po_number',
        'arrival_type',
        'plan_delivery_date',
        'plan_delivery_time',
        'bp_code',
        'driver_name',
        'vehicle_plate',
        'schedule_id',
        'related_arrival_id',
        'security_checkin_time',
        'security_checkout_time',
        'security_duration',
        'warehouse_checkin_time',
        'warehouse_checkout_time',
        'completed_at',
        'warehouse_duration',
        'status',
        'delivery_compliance',
        'pic_receiving',
        'visitor_id',
    ];

    protected $casts = [
        'plan_delivery_date' => 'date',
        'plan_delivery_time' => 'datetime:H:i',
        'security_checkin_time' => 'datetime',
        'security_checkout_time' => 'datetime',
        'warehouse_checkin_time' => 'datetime',
        'warehouse_checkout_time' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the arrival schedule for this transaction
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ArrivalSchedule::class, 'schedule_id');
    }

    /**
     * Arrival that this record is completing (for additional arrivals)
     */
    public function relatedArrival(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_arrival_id');
    }

    /**
     * Child arrivals that complete this DN
     */
    public function followUpArrivals(): HasMany
    {
        return $this->hasMany(self::class, 'related_arrival_id');
    }

    /**
     * Get DN scan sessions for this arrival
     */
    public function scanSessions(): HasMany
    {
        return $this->hasMany(DnScanSession::class, 'arrival_id');
    }

    /**
     * Get scanned items for this arrival
     */
    public function scannedItems(): HasMany
    {
        return $this->hasMany(ScannedItem::class, 'arrival_id');
    }

    /**
     * Scope for regular arrivals
     */
    public function scopeRegular($query)
    {
        return $query->where('arrival_type', 'regular');
    }

    /**
     * Scope for additional arrivals
     */
    public function scopeAdditional($query)
    {
        return $query->where('arrival_type', 'additional');
    }

    /**
     * Scope for specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('plan_delivery_date', $date);
    }

    /**
     * Scope for specific supplier
     */
    public function scopeForSupplier($query, $bpCode)
    {
        return $query->where('bp_code', $bpCode);
    }

    /**
     * Scope for pending arrivals
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for completed arrivals
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', '!=', 'pending');
    }

    /**
     * Calculate security duration
     */
    public function calculateSecurityDuration()
    {
        if ($this->security_checkin_time && $this->security_checkout_time) {
            $this->security_duration = $this->security_checkin_time->diffInMinutes($this->security_checkout_time);
            $this->save();
        }
    }

    /**
     * Calculate warehouse duration
     */
    public function calculateWarehouseDuration()
    {
        if ($this->warehouse_checkin_time && $this->warehouse_checkout_time) {
            $this->warehouse_duration = $this->warehouse_checkin_time->diffInMinutes($this->warehouse_checkout_time);
            $this->save();
        }
    }

    /**
     * Get total quantity DN
     */
    public function getTotalQuantityDnAttribute()
    {
        return $this->scannedItems()->sum('expected_quantity');
    }

    /**
     * Get total quantity actual
     */
    public function getTotalQuantityActualAttribute()
    {
        return $this->scannedItems()->sum('scanned_quantity');
    }

    /**
     * Get scan status
     */
    public function getScanStatusAttribute()
    {
        $sessions = $this->scanSessions;
        if ($sessions->isEmpty()) {
            return 'not_started';
        }
        
        $completedSessions = $sessions->where('status', 'completed')->count();
        $totalSessions = $sessions->count();
        
        if ($completedSessions === $totalSessions) {
            return 'completed';
        } elseif ($completedSessions > 0) {
            return 'in_progress';
        } else {
            return 'not_started';
        }
    }

    /**
     * Apply delivery compliance status with priority logic (worst value wins)
     */
    public function applyComplianceStatus(string $status): void
    {
        if (!array_key_exists($status, self::COMPLIANCE_PRIORITIES)) {
            return;
        }

        $current = $this->delivery_compliance ?? self::DELIVERY_COMPLIANCE_PENDING;
        if (!array_key_exists($current, self::COMPLIANCE_PRIORITIES)) {
            $current = self::DELIVERY_COMPLIANCE_PENDING;
        }

        $newPriority = self::COMPLIANCE_PRIORITIES[$status];
        $currentPriority = self::COMPLIANCE_PRIORITIES[$current];

        if ($status === $current) {
            return;
        }

        if ($current === self::DELIVERY_COMPLIANCE_PENDING) {
            $this->delivery_compliance = $status;
            return;
        }

        if ($newPriority >= $currentPriority) {
            $this->delivery_compliance = $status;
        }
    }

    /**
     * Mark arrival as completed and update compliance based on plan date
     */
    public function markCompleted(?Carbon $timestamp = null): void
    {
        $this->completed_at = $timestamp ?? now();

        if ($this->plan_delivery_date) {
            $completedDate = $this->completed_at->toDateString();
            if ($completedDate > $this->plan_delivery_date) {
                $this->applyComplianceStatus(self::DELIVERY_COMPLIANCE_DELAY);
                return;
            }
        }

        if ($this->delivery_compliance === self::DELIVERY_COMPLIANCE_PENDING) {
            $this->delivery_compliance = self::DELIVERY_COMPLIANCE_ON_COMMITMENT;
        }
    }

    /**
     * Update compliance based on warehouse check-in timeline
     */
    public function refreshComplianceFromTimeline(): void
    {
        if (!$this->plan_delivery_date) {
            return;
        }

        $reference = null;
        if ($this->completed_at) {
            $reference = $this->completed_at;
        } elseif ($this->warehouse_checkout_time) {
            $reference = $this->warehouse_checkout_time;
        } elseif ($this->warehouse_checkin_time) {
            $reference = $this->warehouse_checkin_time;
        } elseif ($this->security_checkin_time) {
            $reference = $this->security_checkin_time;
        }

        if (!$reference) {
            return;
        }

        $referenceDate = $reference->toDateString();

        if ($referenceDate > $this->plan_delivery_date) {
            $this->applyComplianceStatus(self::DELIVERY_COMPLIANCE_DELAY);
        } elseif ($this->delivery_compliance === self::DELIVERY_COMPLIANCE_PENDING) {
            $this->delivery_compliance = self::DELIVERY_COMPLIANCE_ON_COMMITMENT;
        }
    }

    public function markAsPartial(): void
    {
        $this->applyComplianceStatus(self::DELIVERY_COMPLIANCE_PARTIAL);
    }

    public function markAsIncompleteQuantity(): void
    {
        $this->applyComplianceStatus(self::DELIVERY_COMPLIANCE_INCOMPLETE);
    }

    public function markAsNoShow(): void
    {
        $this->applyComplianceStatus(self::DELIVERY_COMPLIANCE_NO_SHOW);
    }
}
