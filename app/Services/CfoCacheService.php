<?php

namespace App\Services;

use App\Services\Concerns\HasCacheTags;

/**
 * CfoCacheService — CFO dashboard aggregate cache.
 *
 * Tag: cfo
 * Key: monthly_summary_{YYYY}_{MM}
 * TTL: 600 seconds (10 minutes — financial data updates frequently)
 *
 * Flush triggers: monthly closing, new invoice paid, refund
 *
 * Usage:
 *   app(CfoCacheService::class)->remember($year, $month, $resolver)
 *   app(CfoCacheService::class)->clear()
 *   app(CfoCacheService::class)->clearMonth($year, $month)
 */
class CfoCacheService
{
    use HasCacheTags;

    public const CACHE_TAG = 'cfo';
    public const CACHE_KEY_PREFIX = 'monthly_summary_';
    public const TTL = 600; // 10 minutes

    /**
     * Build cache key for a specific month.
     */
    public function key(int $year, int $month): string
    {
        return self::CACHE_KEY_PREFIX . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Get cached dashboard data for a specific month (versioned).
     *
     * @param  int      $year
     * @param  int      $month
     * @param  \Closure $resolver  Callable that returns the dashboard array
     * @return mixed
     */
    public function remember(int $year, int $month, \Closure $resolver): mixed
    {
        $baseKey = $this->key($year, $month);
        $key = $this->versionedKey(self::CACHE_TAG, $baseKey);

        return $this->tagRemember(self::CACHE_TAG, $key, self::TTL, $resolver);
    }

    /**
     * Invalidate ALL CFO caches via version bump.
     *
     * increment('cfo') → version bump → auto warm queue
     */
    public function clear(): void
    {
        $this->bumpVersion(self::CACHE_TAG);
    }

    /**
     * Invalidate cache for a specific month.
     * With versioning, bumps domain version — all months get new version.
     * (MoM growth figures are interdependent)
     */
    public function clearMonth(int $year, int $month): void
    {
        $this->bumpVersion(self::CACHE_TAG);
    }

    /**
     * Build fallback keys for non-tag drivers.
     * Covers current month ± 2 months (most common access pattern).
     */
    private function buildFallbackKeys(): array
    {
        $keys = [];
        $now = now();

        for ($i = -2; $i <= 1; $i++) {
            $date = $now->copy()->addMonths($i);
            $keys[] = $this->key($date->year, $date->month);
        }

        return $keys;
    }
}
