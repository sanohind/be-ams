<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArrivalSchedule extends Model
{
    use HasFactory;

    protected $table = 'arrival_schedule';

    protected $fillable = [
        'bp_code',
        'day_name',
        'arrival_type',
        'schedule_date',
        'arrival_time',
        'departure_time',
        'dock',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'arrival_time' => 'datetime:H:i',
        'departure_time' => 'datetime:H:i',
    ];

    /**
     * Get arrival transactions for this schedule
     */
    public function arrivalTransactions(): HasMany
    {
        return $this->hasMany(ArrivalTransaction::class, 'schedule_id');
    }

    /**
     * Scope for regular schedules
     */
    public function scopeRegular($query)
    {
        return $query->where('arrival_type', 'regular');
    }

    /**
     * Scope for additional schedules
     */
    public function scopeAdditional($query)
    {
        return $query->where('arrival_type', 'additional');
    }

    /**
     * Scope for specific day
     */
    public function scopeForDay($query, $dayName)
    {
        return $query->where('day_name', $dayName);
    }

    /**
     * Scope for specific supplier
     */
    public function scopeForSupplier($query, $bpCode)
    {
        return $query->where('bp_code', $bpCode);
    }
}
