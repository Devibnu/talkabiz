<?php

namespace App\Services;

use App\Models\TaxSettings;
use App\Services\Concerns\HasCacheTags;

/**
 * TaxCacheService — Cached tax configuration.
 *
 * Tag: tax
 * Key: tax_default
 * TTL: 3600 seconds (1 hour — tax config rarely changes)
 *
 * Flush triggers: update tax settings
 *
 * Usage:
 *   app(TaxCacheService::class)->getDefaultRate()
 *   app(TaxCacheService::class)->getSettings()
 *   app(TaxCacheService::class)->clear()
 */
class TaxCacheService
{
    use HasCacheTags;

    public const CACHE_TAG = 'tax';
    public const CACHE_KEY = 'tax_default';
    public const TTL = 3600;

    /**
     * Get active tax settings (cached, versioned).
     */
    public function getSettings(): ?TaxSettings
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::CACHE_KEY);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, function () {
            try {
                return TaxSettings::getActive();
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Get default PPN rate (cached).
     */
    public function getDefaultRate(): float
    {
        $settings = $this->getSettings();

        return $settings?->default_ppn_rate ?? 11.00;
    }

    /**
     * Invalidate tax cache via version bump.
     *
     * increment('tax') → version bump → auto warm queue
     */
    public function clear(): void
    {
        $this->bumpVersion(self::CACHE_TAG);
    }

    /**
     * Re-warm tax cache (called by cache:warm command).
     */
    public function warm(): void
    {
        $this->getSettings();
    }
}
