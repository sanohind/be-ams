<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class SphereRole extends Model
{
    protected $connection = 'sphere';
    protected $table = 'roles';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'name',
        'slug',
        'level',
        'department_id',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get users with this role
     */
    public function users()
    {
        return $this->hasMany(SphereUser::class, 'role_id');
    }

    /**
     * Get department
     */
    public function department()
    {
        return $this->belongsTo(SphereDepartment::class, 'department_id');
    }

    /**
     * Scope for active roles
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific level
     */
    public function scopeLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope for specific slug
     */
    public function scopeSlug($query, $slug)
    {
        return $query->where('slug', $slug);
    }
}
