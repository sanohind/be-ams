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
        'total_quantity',
        'lot_number',
        'customer',
        'qr_raw_data',
        'dn_detail_no',
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
     * Get progress percentage
     */
    public function getProgressAttribute()
    {
        if ($this->total_quantity <= 0) {
            return 0;
        }
        return round(($this->scanned_quantity / $this->total_quantity) * 100, 2);
    }

    /**
     * Check if scanning is complete
     */
    public function isComplete()
    {
        return $this->scanned_quantity >= $this->total_quantity;
    }
}
