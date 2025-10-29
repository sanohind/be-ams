<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'description',
    ];

    public $timestamps = false;

    /**
     * Get setting value by key
     */
    public static function getValue($key, $default = null)
    {
        $setting = self::where('setting_key', $key)->first();
        return $setting ? $setting->setting_value : $default;
    }

    /**
     * Set setting value by key
     */
    public static function setValue($key, $value, $description = null)
    {
        return self::updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => $value,
                'description' => $description,
            ]
        );
    }

    /**
     * Get all settings as key-value array
     */
    public static function getAllAsArray()
    {
        return self::pluck('setting_value', 'setting_key')->toArray();
    }

    /**
     * Check if setting exists
     */
    public static function exists($key)
    {
        return self::where('setting_key', $key)->exists();
    }

    /**
     * Delete setting by key
     */
    public static function deleteByKey($key)
    {
        return self::where('setting_key', $key)->delete();
    }
}
