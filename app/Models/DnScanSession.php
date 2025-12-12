<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnScanSession extends Model
{
    use HasFactory;

    protected $table = 'dn_scan_sessions';

    protected $fillable = [
        'arrival_id',
        'dn_number',
        'operator_id',
        'session_start',
        'session_end',
        'session_duration',
        'total_items_scanned',
        'status',
        'label_part_status',
        'coa_msds_status',
        'packing_condition_status',
    ];

    protected $casts = [
        'session_start' => 'datetime',
        'session_end' => 'datetime',
    ];

    /**
     * Get the arrival transaction for this session
     */
    public function arrival(): BelongsTo
    {
        return $this->belongsTo(ArrivalTransaction::class, 'arrival_id');
    }

    /**
     * Get scanned items for this session
     */
    public function scannedItems(): HasMany
    {
        return $this->hasMany(ScannedItem::class, 'session_id');
    }

    /**
     * Scope for in progress sessions
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope for completed sessions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for specific DN
     */
    public function scopeForDn($query, $dnNumber)
    {
        return $query->where('dn_number', $dnNumber);
    }

    /**
     * End the session
     */
    public function endSession()
    {
        $this->session_end = now();
        $this->session_duration = $this->session_start->diffInMinutes($this->session_end);
        $this->status = 'completed';
        $this->save();

        // Update pic_receiving in arrival transaction with operator_id from this session
        if ($this->arrival_id && $this->operator_id) {
            $arrival = $this->arrival;
            if ($arrival && !$arrival->pic_receiving) {
                $arrival->pic_receiving = (string) $this->operator_id;
                $arrival->save();
            }
        }
    }

    /**
     * Check if all quality checks are completed
     */
    public function isQualityCheckCompleted()
    {
        return $this->label_part_status !== 'PENDING' &&
               $this->coa_msds_status !== 'PENDING' &&
               $this->packing_condition_status !== 'PENDING';
    }

    /**
     * Get total scanned quantity
     */
    public function getTotalScannedQuantityAttribute()
    {
        return $this->scannedItems()->sum('scanned_quantity');
    }

    /**
     * Get total expected quantity
     */
    public function getTotalExpectedQuantityAttribute()
    {
        return $this->scannedItems()->sum('expected_quantity');
    }
}
