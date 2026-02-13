<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $table = 'site_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    // ==================== SCOPES ====================

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    public function scopeBranding($query)
    {
        return $query->where('group', 'branding');
    }

    // ==================== STATIC HELPERS ====================

    /**
     * Get a setting value by key, with optional default.
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting?->value ?? $default;
    }

    /**
     * Set a setting value by key.
     */
    public static function setValue(string $key, $value): void
    {
        static::where('key', $key)->update(['value' => $value]);
    }

    /**
     * Get the full URL for a file-type setting.
     */
    public static function getFileUrl(string $key, ?string $fallback = null): ?string
    {
        $value = static::getValue($key);

        if ($value) {
            return asset('storage/' . $value);
        }

        return $fallback;
    }

    /**
     * Get all branding settings as key-value array.
     */
    public static function getBranding(): array
    {
        return static::branding()
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }
}
