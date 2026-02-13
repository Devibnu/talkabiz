<?php

namespace App\Services\Concerns;

use App\Services\CacheVersionService;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * HasCacheTags — Reusable trait for versioned caching with optional Redis tags.
 *
 * HYBRID CACHE STRATEGY per domain:
 *   1. version key  → atomic integer (managed by CacheVersionService)
 *   2. cache key    → {baseKey}_v{version}
 *   3. warm queue   → auto-populated on version increment
 *   4. optional tag → Redis Cache::tags() for monitoring (graceful fallback)
 *
 * On data change:
 *   bumpVersion('settings')
 *     → CacheVersionService::increment('settings')
 *     → version 1→2 (atomic)
 *     → auto-queued for smart warm
 *     → old keys become orphans (expire via TTL)
 *     → zero flush, zero stale, zero DB spike
 *
 * DOMAIN MAP:
 *   'settings'          → system settings         (TTL 3600)
 *   'plans'             → plan catalog             (TTL 3600)
 *   'landing'           → landing page content     (TTL 1800)
 *   'wallet:user_{id}'  → per-user wallet balance  (TTL 300, not warmable)
 *   'tax'               → tax config               (TTL 3600)
 *   'cfo'               → CFO dashboard aggregates (TTL 600)
 */
trait HasCacheTags
{
    // ==================== VERSIONING ====================

    /**
     * Build a versioned cache key: "{baseKey}_v{version}"
     */
    protected function versionedKey(string $domain, string $baseKey): string
    {
        return app(CacheVersionService::class)->versionedKey($domain, $baseKey);
    }

    /**
     * Increment domain version — THE invalidation method.
     *
     * - Atomic increment (multi-node safe on Redis)
     * - Auto-queues domain for smart warm
     * - Old keys become orphans → expire via TTL
     * - Zero flush, zero thundering herd
     */
    protected function bumpVersion(string $domain): int
    {
        return app(CacheVersionService::class)->increment($domain);
    }

    // ==================== TAG-AWARE CACHING ====================

    /**
     * Whether the current cache store supports tags (Redis/Memcached).
     */
    protected function supportsTag(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Cache::remember with optional tag support.
     *
     * On Redis: Cache::tags($tags)->remember($key, $ttl, $resolver)
     * On file:  Cache::remember($key, $ttl, $resolver)
     *
     * Use with versionedKey() for full hybrid caching.
     */
    protected function tagRemember(string|array $tags, string $key, int $ttl, \Closure $resolver): mixed
    {
        $tags = (array) $tags;

        if ($this->supportsTag()) {
            return Cache::tags($tags)->remember($key, $ttl, $resolver);
        }

        return Cache::remember($key, $ttl, $resolver);
    }

    /**
     * Emergency flush for tag(s).
     *
     * @deprecated Use bumpVersion() instead. Kept only for emergency manual flush.
     */
    protected function tagFlush(string|array $tags, array $fallbackKeys = []): void
    {
        $tags = (array) $tags;

        if ($this->supportsTag()) {
            Cache::tags($tags)->flush();
        } else {
            foreach ($fallbackKeys as $key) {
                Cache::forget($key);
            }
        }
    }
}
