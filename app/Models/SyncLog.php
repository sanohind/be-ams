<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    protected $table = 'sync_logs';

    protected $fillable = [
        'sync_status',
        'records_synced',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Scope for successful syncs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('sync_status', 'success');
    }

    /**
     * Scope for failed syncs
     */
    public function scopeFailed($query)
    {
        return $query->where('sync_status', 'failed');
    }

    /**
     * Scope for partial syncs
     */
    public function scopePartial($query)
    {
        return $query->where('sync_status', 'partial');
    }

    /**
     * Scope for recent syncs
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Calculate sync duration
     */
    public function getDurationAttribute()
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInMinutes($this->completed_at);
        }
        return null;
    }

    /**
     * Mark sync as started
     */
    public function markAsStarted()
    {
        $this->started_at = now();
        $this->save();
    }

    /**
     * Mark sync as completed
     */
    public function markAsCompleted($status = 'success', $recordsSynced = 0, $errorMessage = null)
    {
        $this->sync_status = $status;
        $this->records_synced = $recordsSynced;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Create a new sync log
     */
    public static function createLog()
    {
        return self::create([
            'sync_status' => 'pending',
            'records_synced' => 0,
            'started_at' => now(),
        ]);
    }
}
