<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryPerformance extends Model
{
    use HasFactory;

    protected $table = 'delivery_performance';

    protected $fillable = [
        'bp_code',
        'period_month',
        'period_year',
        'total_delay_days',
        'total_deliveries',
        'on_time_deliveries',
        'ranking',
        'category',
        'calculated_at',
    ];

    protected $casts = [
        'calculated_at' => 'datetime',
    ];

    /**
     * Scope for specific period
     */
    public function scopeForPeriod($query, $year, $month)
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    /**
     * Scope for specific supplier
     */
    public function scopeForSupplier($query, $bpCode)
    {
        return $query->where('bp_code', $bpCode);
    }

    /**
     * Scope for best performers
     */
    public function scopeBest($query)
    {
        return $query->where('category', 'best');
    }

    /**
     * Scope for worst performers
     */
    public function scopeWorst($query)
    {
        return $query->where('category', 'worst');
    }

    /**
     * Calculate on-time delivery percentage
     */
    public function getOnTimePercentageAttribute()
    {
        if ($this->total_deliveries === 0) {
            return 0;
        }
        return round(($this->on_time_deliveries / $this->total_deliveries) * 100, 2);
    }

    /**
     * Calculate delay percentage
     */
    public function getDelayPercentageAttribute()
    {
        if ($this->total_deliveries === 0) {
            return 0;
        }
        $delayDeliveries = $this->total_deliveries - $this->on_time_deliveries;
        return round(($delayDeliveries / $this->total_deliveries) * 100, 2);
    }

    /**
     * Update category based on ranking
     */
    public function updateCategory()
    {
        if ($this->ranking <= 3) {
            $this->category = 'best';
        } elseif ($this->ranking >= 8) {
            $this->category = 'worst';
        } else {
            $this->category = 'medium';
        }
        $this->save();
    }
}
