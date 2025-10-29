<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class SphereDepartment extends Model
{
    protected $connection = 'sphere';
    protected $table = 'departments';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'name',
        'code',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get roles in this department
     */
    public function roles()
    {
        return $this->hasMany(SphereRole::class, 'department_id');
    }

    /**
     * Get users in this department
     */
    public function users()
    {
        return $this->hasMany(SphereUser::class, 'department_id');
    }

    /**
     * Scope for active departments
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific code
     */
    public function scopeCode($query, $code)
    {
        return $query->where('code', $code);
    }
}
