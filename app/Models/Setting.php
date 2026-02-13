<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Setting — Single-row system configuration.
 *
 * SSOT for core system config (company info, contact, financial defaults).
 * NOT for marketing/landing content (use LandingSection for that).
 * NOT for branding/logo (use SiteSetting + BrandingService for that).
 *
 * Always accessed via Setting::instance() to ensure single-row guarantee.
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'company_name',
        'company_address',
        'contact_email',
        'contact_phone',
        'sales_whatsapp',
        'maps_embed_url',
        'maps_link',
        'default_currency',
        'default_tax_percent',
        'operating_hours',
    ];

    protected $casts = [
        'default_tax_percent' => 'decimal:2',
    ];

    /** Cache key and TTL */
    private const CACHE_KEY = 'system_settings';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get the single settings row (cached via SettingsService).
     * Prefer using app(SettingsService::class)->get() instead.
     *
     * @deprecated Use app(SettingsService::class)->get()
     */
    public static function instance(): self
    {
        return app(\App\Services\SettingsService::class)->get();
    }

    /**
     * Get contact phone as a wa.me URL.
     */
    public function getContactPhoneUrlAttribute(): string
    {
        $number = preg_replace('/[^0-9]/', '', $this->contact_phone ?? '6281234567890');
        return 'https://wa.me/' . $number;
    }

    /**
     * Get sales WhatsApp as a wa.me URL.
     */
    public function getSalesWhatsappUrlAttribute(): ?string
    {
        if (!$this->sales_whatsapp) {
            return null;
        }
        $number = preg_replace('/[^0-9]/', '', $this->sales_whatsapp);
        return 'https://wa.me/' . $number;
    }

    /**
     * Clear all related caches (delegates to SettingsService).
     */
    public static function clearCache(): void
    {
        app(\App\Services\SettingsService::class)->clear();
    }

    /**
     * Override save to auto-clear cache.
     */
    public function save(array $options = [])
    {
        $result = parent::save($options);
        static::clearCache();
        return $result;
    }
}
