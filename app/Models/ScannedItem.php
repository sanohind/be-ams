<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScannedItem extends Model
{
    use HasFactory;

    protected $table = 'scanned_items';

    protected $fillable = [
        'session_id',
        'arrival_id',
        'dn_number',
        'part_no',
        'scanned_quantity',
        'lot_number',
        'customer',
        'qr_raw_data',
        'dn_detail_no',
        'expected_quantity',
        'match_status',
        'scanned_by',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    /**
     * Get the scan session for this item
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DnScanSession::class, 'session_id');
    }

    /**
     * Get the arrival transaction for this item
     */
    public function arrival(): BelongsTo
    {
        return $this->belongsTo(ArrivalTransaction::class, 'arrival_id');
    }

    /**
     * Scope for matched items
     */
    public function scopeMatched($query)
    {
        return $query->where('match_status', 'matched');
    }

    /**
     * Scope for items not found
     */
    public function scopeNotFound($query)
    {
        return $query->where('match_status', 'not_found');
    }

    /**
     * Scope for quantity mismatch
     */
    public function scopeQuantityMismatch($query)
    {
        return $query->where('match_status', 'quantity_mismatch');
    }

    /**
     * Scope for specific DN
     */
    public function scopeForDn($query, $dnNumber)
    {
        return $query->where('dn_number', $dnNumber);
    }

    /**
     * Scope for specific part number
     */
    public function scopeForPart($query, $partNo)
    {
        return $query->where('part_no', $partNo);
    }

    /**
     * Check if quantity matches expected
     */
    public function isQuantityMatched()
    {
        return $this->scanned_quantity === $this->expected_quantity;
    }

    /**
     * Get quantity variance
     */
    public function getQuantityVarianceAttribute()
    {
        return $this->scanned_quantity - $this->expected_quantity;
    }

    /**
     * Update match status based on quantity comparison
     */
    public function updateMatchStatus()
    {
        if ($this->expected_quantity === 0) {
            $this->match_status = 'not_found';
        } elseif ($this->scanned_quantity === $this->expected_quantity) {
            $this->match_status = 'matched';
        } else {
            $this->match_status = 'quantity_mismatch';
        }
        $this->save();
    }
}
