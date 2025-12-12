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
        'total_dn_qty',
        'total_receipt_qty',
        'fulfillment_percentage',
        'fulfillment_index',
        'delivery_index',
        'total_index',
        'final_score',
        'performance_grade',
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
     * Update category based on final score
     */
    public function updateCategory()
    {
        if ($this->final_score >= 90) {
            $this->category = 'best';
        } elseif ($this->final_score >= 70) {
            $this->category = 'medium';
        } else {
            $this->category = 'worst';
        }
        $this->save();
    }

    /**
     * Scope for specific grade
     */
    public function scopeWithGrade($query, $grade)
    {
        return $query->where('performance_grade', $grade);
    }

    /**
     * Scope ordered by final score descending
     */
    public function scopeOrderedByScore($query)
    {
        return $query->orderBy('final_score', 'desc');
    }
}
