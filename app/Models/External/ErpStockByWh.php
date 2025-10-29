<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class ErpStockByWh extends Model
{
    protected $connection = 'erp';
    protected $table = 'stockbywh';

    protected $fillable = [
        'warehouse',
        'partno',
        'desc',
        'partname',
        'oldpartno',
        'group',
        'groupkey',
        'product_type',
        'model',
        'customer',
        'onhand',
        'allocated',
        'onorder',
        'economicstock',
        'safety_stock',
        'min_stock',
        'max_stock',
        'unit',
        'location',
    ];

    protected $casts = [
        'onhand' => 'integer',
        'allocated' => 'integer',
        'onorder' => 'integer',
        'economicstock' => 'integer',
        'safety_stock' => 'integer',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
    ];

    /**
     * Scope for specific warehouse
     */
    public function scopeForWarehouse($query, $warehouse)
    {
        return $query->where('warehouse', $warehouse);
    }

    /**
     * Scope for specific part number
     */
    public function scopeForPart($query, $partNo)
    {
        return $query->where('partno', $partNo);
    }

    /**
     * Scope for low stock items
     */
    public function scopeLowStock($query)
    {
        return $query->whereRaw('onhand <= min_stock');
    }

    /**
     * Scope for out of stock items
     */
    public function scopeOutOfStock($query)
    {
        return $query->where('onhand', '<=', 0);
    }

    /**
     * Scope for items with stock
     */
    public function scopeInStock($query)
    {
        return $query->where('onhand', '>', 0);
    }

    /**
     * Calculate stock status
     */
    public function getStockStatusAttribute()
    {
        if ($this->onhand <= 0) {
            return 'out_of_stock';
        } elseif ($this->onhand <= $this->min_stock) {
            return 'low_stock';
        } elseif ($this->onhand >= $this->max_stock) {
            return 'overstock';
        } else {
            return 'normal';
        }
    }

    /**
     * Calculate available stock
     */
    public function getAvailableStockAttribute()
    {
        return $this->onhand - $this->allocated;
    }
}
