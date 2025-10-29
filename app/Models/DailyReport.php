<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    use HasFactory;

    protected $table = 'daily_reports';

    protected $fillable = [
        'report_date',
        'total_arrivals',
        'total_on_time',
        'total_delay',
        'file_path',
        'generated_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'generated_at' => 'datetime',
    ];

    /**
     * Scope for specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('report_date', $date);
    }

    /**
     * Scope for date range
     */
    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('report_date', [$startDate, $endDate]);
    }

    /**
     * Calculate on-time percentage
     */
    public function getOnTimePercentageAttribute()
    {
        if ($this->total_arrivals === 0) {
            return 0;
        }
        return round(($this->total_on_time / $this->total_arrivals) * 100, 2);
    }

    /**
     * Calculate delay percentage
     */
    public function getDelayPercentageAttribute()
    {
        if ($this->total_arrivals === 0) {
            return 0;
        }
        return round(($this->total_delay / $this->total_arrivals) * 100, 2);
    }

    /**
     * Generate report for specific date
     */
    public static function generateForDate($date)
    {
        $arrivals = ArrivalTransaction::forDate($date)->get();
        
        $totalArrivals = $arrivals->count();
        $totalOnTime = $arrivals->where('status', 'on_time')->count();
        $totalDelay = $arrivals->where('status', 'delay')->count();

        return self::create([
            'report_date' => $date,
            'total_arrivals' => $totalArrivals,
            'total_on_time' => $totalOnTime,
            'total_delay' => $totalDelay,
            'generated_at' => now(),
        ]);
    }
}
