<?php

namespace App\Models\External;

use Illuminate\Database\Eloquent\Model;

class SphereUser extends Model
{
    protected $connection = 'sphere';
    protected $table = 'users';
    protected $primaryKey = 'id';

    protected $fillable = [
        'id',
        'email',
        'username',
        'password',
        'name',
        'nik',
        'phone_number',
        'avatar',
        'role_id',
        'department_id',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get user role
     */
    public function role()
    {
        return $this->belongsTo(SphereRole::class, 'role_id');
    }

    /**
     * Get user department
     */
    public function department()
    {
        return $this->belongsTo(SphereDepartment::class, 'department_id');
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific role
     */
    public function scopeWithRole($query, $roleSlug)
    {
        return $query->whereHas('role', function($q) use ($roleSlug) {
            $q->where('slug', $roleSlug);
        });
    }

    /**
     * Check if user has specific role
     */
    public function hasRole($roleSlug)
    {
        return $this->role && $this->role->slug === $roleSlug;
    }

    /**
     * Check if user has any of the specified roles
     */
    public function hasAnyRole($roleSlugs)
    {
        if (!$this->role) {
            return false;
        }
        
        return in_array($this->role->slug, (array) $roleSlugs);
    }
}
