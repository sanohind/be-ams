<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class ScmDnDetail extends Model
{
    protected $connection = 'scm';
    protected $table = 'dn_detail';
    protected $primaryKey = 'dn_detail_no';

    protected $fillable = [
        'dn_detail_no',
        'no_dn',
        'dn_line',
        'order_origin',
        'plan_delivery_date',
        'plan_delivery_time',
        'actual_receipt_date',
        'actual_receipt_time',
        'no_order',
        'order_set',
        'order_line',
        'order_seq',
        'part_no',
        'supplier_item_no',
        'item_desc_a',
        'item_desc_b',
        'item_customer',
        'lot_number',
        'dn_qty',
        'receipt_qty',
        'dn_unit',
        'dn_snp',
        'reference',
        'status_desc',
        'qty_confirm',
    ];

    protected $casts = [
        'plan_delivery_date' => 'date',
        'plan_delivery_time' => 'datetime:H:i',
        'actual_receipt_date' => 'date',
        'actual_receipt_time' => 'datetime:H:i',
    ];

    /**
     * Get DN header
     */
    public function header()
    {
        return $this->belongsTo(ScmDnHeader::class, 'no_dn', 'no_dn');
    }

    /**
     * Scope for specific DN
     */
    public function scopeForDn($query, $dnNumber)
    {
        return $query->where('no_dn', $dnNumber);
    }

    /**
     * Scope for specific part number
     */
    public function scopeForPart($query, $partNo)
    {
        return $query->where('part_no', $partNo);
    }
}
