<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class ScmDnHeader extends Model
{
    protected $connection = 'scm';
    protected $table = 'dn_header';
    protected $primaryKey = 'no_dn';

    protected $fillable = [
        'no_dn',
        'po_no',
        'supplier_code',
        'supplier_name',
        'dn_created_date',
        'dn_year',
        'dn_period',
        'plan_delivery_date',
        'plan_delivery_time',
        'status_desc',
        'confirm_update_at',
        'dn_printed_at',
        'dn_label_printed_at',
        'packing_slip',
        'driver_name',
        'plat_number',
    ];

    protected $casts = [
        'dn_created_date' => 'date',
        'plan_delivery_date' => 'date',
        'plan_delivery_time' => 'datetime:H:i',
        'confirm_update_at' => 'datetime',
        'dn_printed_at' => 'datetime',
        'dn_label_printed_at' => 'datetime',
    ];

    /**
     * Get DN details
     */
    public function details()
    {
        return $this->hasMany(ScmDnDetail::class, 'no_dn', 'no_dn');
    }

    /**
     * Scope for open status
     */
    public function scopeOpen($query)
    {
        return $query->where('status_desc', 'Open');
    }

    /**
     * Scope for specific supplier
     */
    public function scopeForSupplier($query, $supplierCode)
    {
        return $query->where('supplier_code', $supplierCode);
    }

    /**
     * Scope for date range
     */
    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('plan_delivery_date', [$startDate, $endDate]);
    }
}
