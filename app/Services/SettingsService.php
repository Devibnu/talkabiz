<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Concerns\HasCacheTags;
use Illuminate\Support\Facades\Cache;

/**
 * SettingsService — Enterprise-grade cached layer for system settings.
 *
 * SSOT for system configuration (company info, contact, financial defaults).
 * Uses Cache Tags when driver supports it (Redis/Memcached) for isolated flush,
 * with graceful fallback to standard cache for file/database drivers.
 *
 * Usage:
 *   app(SettingsService::class)->get()    — cached Setting model
 *   app(SettingsService::class)->clear()  — bust settings + branding caches
 *
 * Registered as singleton in AppServiceProvider.
 */
class SettingsService
{
    use HasCacheTags;

    /** Cache key for system settings */
    public const CACHE_KEY = 'system_settings';

    /** Cache tag for isolated flush (Redis/Memcached only) */
    public const CACHE_TAG = 'settings';

    /** Cache TTL in seconds (1 hour) */
    public const TTL = 3600;

    /**
     * Get the system settings (cached).
     * Auto-creates row id=1 with sane defaults if missing.
     */
    public function get(): Setting
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::CACHE_KEY);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, function () {
            return Setting::firstOrCreate(
                ['id' => 1],
                [
                    'company_name'        => 'Talkabiz',
                    'company_address'     => 'Jakarta, Indonesia',
                    'contact_email'       => 'support@talkabiz.id',
                    'contact_phone'       => '+62 812-3456-7890',
                    'default_currency'    => 'IDR',
                    'default_tax_percent' => 11.00,
                    'operating_hours'     => 'Senin – Jumat, 09.00 – 17.00 WIB',
                ]
            );
        });
    }

    /**
     * Invalidate settings caches via version bump.
     *
     * increment('settings') → version 1→2 → auto warm queue
     * Old versioned key becomes orphan → expires via TTL
     * Next get() → cache miss on v2 → DB → fresh data cached
     */
    public function clear(): void
    {
        $this->bumpVersion(self::CACHE_TAG);

        // Always clear branding cache (BrandingService reads from settings table)
        Cache::forget('branding_settings');
    }

    /**
     * Re-warm settings cache (called by cache:warm command).
     */
    public function warm(): void
    {
        $this->get();
    }

    /**
     * Backward-compatible alias.
     * @deprecated Use clear() instead.
     */
    public function clearCache(): void
    {
        $this->clear();
    }
}
