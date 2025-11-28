<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $connection = 'visitor';
    protected $table = 'visitor';
    protected $primaryKey = 'visitor_id';

    protected $fillable = [
        'visitor_id',
        'visitor_date',
        'plan_delivery_time',
        'visitor_name',
        'visitor_from',
        'bp_code',
        'visitor_host',
        'visitor_needs',
        'visitor_amount',
        'visitor_vehicle',
        'department',
        'visitor_img',
        'visitor_checkin',
        'visitor_checkout',
    ];

    protected $casts = [
        'visitor_date' => 'date',
        'plan_delivery_time' => 'datetime:H:i',
        'visitor_checkin' => 'datetime',
        'visitor_checkout' => 'datetime',
    ];

    /**
     * Scope for specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('visitor_date', $date);
    }

    /**
     * Scope for specific supplier
     */
    public function scopeForSupplier($query, $bpCode)
    {
        return $query->where('bp_code', $bpCode);
    }

    /**
     * Scope for specific driver and vehicle
     */
    public function scopeForDriverAndVehicle($query, $driverName, $vehiclePlate)
    {
        return $query->where('visitor_name', $driverName)
                    ->where('visitor_vehicle', $vehiclePlate);
    }

    /**
     * Scope for checked in visitors
     */
    public function scopeCheckedIn($query)
    {
        return $query->whereNotNull('visitor_checkin');
    }

    /**
     * Scope for checked out visitors
     */
    public function scopeCheckedOut($query)
    {
        return $query->whereNotNull('visitor_checkout');
    }

    /**
     * Scope for active visitors (checked in but not checked out)
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('visitor_checkin')
                    ->whereNull('visitor_checkout');
    }
}
