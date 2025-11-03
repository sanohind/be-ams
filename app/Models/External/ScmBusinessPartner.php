<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class ScmBusinessPartner extends Model
{
    protected $connection = 'scm';
    protected $table = 'business_partner';
    protected $primaryKey = 'bp_code';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'bp_code',
        'parent_bp_code',
        'bp_name',
        'bp_status_desc',
        'bp_currency',
        'country',
        'adr_line_1',
        'adr_line_2',
        'adr_line_3',
        'adr_line_4',
        'bp_phone',
        'bp_fax',
        'bp_role',
        'bp_role_desc',
    ];

    /**
     * Scope for active partners
     */
    public function scopeActive($query)
    {
        return $query->whereRaw("LOWER(bp_status_desc) = 'active'");
    }

    /**
     * Scope for suppliers
     */
    public function scopeSuppliers($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw("LOWER(bp_role) = 'supplier'")
              ->orWhere('bp_role', '3')
              ->orWhereRaw("LOWER(bp_role_desc) = 'supplier'");
        });
    }
}
