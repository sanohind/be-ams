<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArrivalTransaction extends Model
{
    use HasFactory;

    protected $table = 'arrival_transactions';

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
        'security_checkin_time',
        'security_checkout_time',
        'security_duration',
        'warehouse_checkin_time',
        'warehouse_checkout_time',
        'warehouse_duration',
        'status',
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
    ];

    /**
     * Get the arrival schedule for this transaction
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ArrivalSchedule::class, 'schedule_id');
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
}
