<?php

namespace App\Services;

use App\Models\LandingSection;
use App\Models\Plan;
use App\Services\Concerns\HasCacheTags;

/**
 * LandingCacheService — Landing page content cache.
 *
 * Tag: landing
 * Keys: landing_sections, landing_plans, landing_popular_plan
 * TTL: 1800 seconds (30 minutes — landing content changes infrequently)
 *
 * Flush triggers: owner edits landing sections/items, plan changes
 *
 * Usage:
 *   app(LandingCacheService::class)->getSections()
 *   app(LandingCacheService::class)->getPlans()
 *   app(LandingCacheService::class)->getPopularPlan()
 *   app(LandingCacheService::class)->clear()
 */
class LandingCacheService
{
    use HasCacheTags;

    public const CACHE_TAG = 'landing';
    public const KEY_SECTIONS = 'landing_sections';
    public const KEY_PLANS = 'landing_plans';
    public const KEY_POPULAR = 'landing_popular_plan';
    public const TTL = 1800; // 30 minutes

    /**
     * Get landing sections with items (cached, versioned).
     */
    public function getSections()
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::KEY_SECTIONS);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, function () {
            return LandingSection::with(['items' => function ($query) {
                $query->active()->ordered();
            }])
                ->active()
                ->ordered()
                ->get();
        });
    }

    /**
     * Get visible active plans for pricing section (cached, versioned).
     */
    public function getPlans()
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::KEY_PLANS);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, function () {
            return Plan::active()
                ->visible()
                ->ordered()
                ->get();
        });
    }

    /**
     * Get popular plan for highlight badge (cached, versioned).
     */
    public function getPopularPlan(): ?Plan
    {
        $key = $this->versionedKey(self::CACHE_TAG, self::KEY_POPULAR);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, function () {
            return Plan::active()
                ->visible()
                ->popular()
                ->first();
        });
    }

    /**
     * Invalidate all landing page caches via version bump.
     *
     * increment('landing') → version bump → auto warm queue
     */
    public function clear(): void
    {
        $this->bumpVersion(self::CACHE_TAG);
    }

    /**
     * Re-warm all landing caches (called by cache:warm command).
     */
    public function warm(): void
    {
        $this->getSections();
        $this->getPlans();
        $this->getPopularPlan();
    }
}
