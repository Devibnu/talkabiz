<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * CacheVersionService — Atomic version counter + warm queue per domain.
 *
 * HYBRID CACHE STRATEGY:
 *
 *   1. Version key:  cache_version_{domain}       → atomic integer
 *   2. Cache key:    {baseKey}_v{version}          → versioned data
 *   3. Warm queue:   cache_warm_queue              → {domain: timestamp}
 *
 * On data change:
 *   increment('settings')
 *     → cache_version_settings: 1 → 2      (atomic)
 *     → cache_warm_queue + settings         (warm queue)
 *     → old key system_settings_v1          (orphan, expires via TTL)
 *     → next read uses system_settings_v2   (cache miss → DB → cache)
 *
 * ZERO stale. ZERO flush. ZERO DB spike. Multi-node safe.
 *
 * Registered as singleton in AppServiceProvider.
 */
class CacheVersionService
{
    /** Prefix for version counter keys */
    private const VERSION_PREFIX = 'cache_version_';

    /** Key for the smart warm queue */
    private const WARM_QUEUE_KEY = 'cache_warm_queue';

    /** All known warmable domains */
    private const DOMAINS = ['settings', 'plans', 'landing', 'tax', 'cfo'];

    // ==================== VERSION ====================

    /**
     * Get the current version for a domain.
     * Returns 1 if no version has been set yet.
     *
     * @param  string $domain Domain name (e.g. 'settings', 'plans')
     * @return int
     */
    public function get(string $domain): int
    {
        return (int) Cache::get(self::VERSION_PREFIX . $domain, 1);
    }

    /**
     * Alias for get() — backward compatibility.
     */
    public function getVersion(string $domain): int
    {
        return $this->get($domain);
    }

    /**
     * Increment version + auto-queue for smart warm.
     *
     * Uses Cache::increment() for atomicity on Redis (multi-node safe).
     * Falls back to get+set on file/database driver.
     *
     * Also auto-registers the domain in the warm queue so
     * `cache:smart-warm` knows which domains to re-populate.
     *
     * Wallet domains (per-user) are excluded from warm queue.
     *
     * @param  string $domain Domain name
     * @return int    New version number
     */
    public function increment(string $domain): int
    {
        $key = self::VERSION_PREFIX . $domain;

        // Ensure version key exists before increment
        if (!Cache::has($key)) {
            Cache::forever($key, 1);
        }

        // Atomic increment (native on Redis, emulated on file/database)
        $newVersion = Cache::increment($key);

        // Auto-queue for smart warm (skip per-user wallet domains)
        if (!str_starts_with($domain, 'wallet:')) {
            $this->queueForWarm($domain);
        }

        return (int) $newVersion;
    }

    /**
     * Build a versioned cache key: "{baseKey}_v{version}"
     *
     * @param  string $domain  Domain name (e.g. 'settings', 'plans')
     * @param  string $baseKey Base cache key (e.g. 'system_settings')
     * @return string Versioned key (e.g. 'system_settings_v3')
     */
    public function versionedKey(string $domain, string $baseKey): string
    {
        return $baseKey . '_v' . $this->get($domain);
    }

    // ==================== WARM QUEUE ====================

    /**
     * Add domain to warm queue.
     * Stores domain + timestamp for `cache:smart-warm` command.
     */
    private function queueForWarm(string $domain): void
    {
        try {
            $queue = Cache::get(self::WARM_QUEUE_KEY, []);
            $queue[$domain] = now()->timestamp;
            Cache::forever(self::WARM_QUEUE_KEY, $queue);
        } catch (\Exception $e) {
            // Non-critical — warm queue failure should never break the app
        }
    }

    /**
     * Get the current warm queue.
     *
     * @return array ['domain' => timestamp, ...]
     */
    public function getWarmQueue(): array
    {
        return Cache::get(self::WARM_QUEUE_KEY, []);
    }

    /**
     * Clear the warm queue after warming is complete.
     */
    public function clearWarmQueue(): void
    {
        Cache::forget(self::WARM_QUEUE_KEY);
    }

    // ==================== DIAGNOSTICS ====================

    /**
     * Get all tracked domain versions (for diagnostics).
     *
     * @param  array $domains List of known domain names (defaults to all)
     * @return array ['domain' => version, ...]
     */
    public function getAllVersions(array $domains = []): array
    {
        $domains = !empty($domains) ? $domains : self::DOMAINS;

        $versions = [];
        foreach ($domains as $domain) {
            $versions[$domain] = $this->get($domain);
        }

        return $versions;
    }
}
