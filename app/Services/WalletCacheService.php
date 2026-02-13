<?php

namespace App\Services;

use App\Models\Wallet;
use App\Services\Concerns\HasCacheTags;
use Illuminate\Support\Facades\Cache;

/**
 * WalletCacheService — Per-user wallet balance cache.
 *
 * Tag: wallet:user_{id}  (isolated per user — flush one user without affecting others)
 * Key: wallet_balance_{id}
 * TTL: 300 seconds (5 minutes — balance is time-sensitive)
 *
 * Flush triggers: topup, deduct, refund, wallet creation
 *
 * Usage:
 *   app(WalletCacheService::class)->getBalance($userId)
 *   app(WalletCacheService::class)->clear($userId)
 */
class WalletCacheService
{
    use HasCacheTags;

    public const CACHE_TAG_PREFIX = 'wallet:user_';
    public const CACHE_KEY_PREFIX = 'wallet_balance_';
    public const TTL = 300; // 5 minutes

    /**
     * Get cached wallet balance for a user (versioned).
     */
    public function getBalance(int $userId): float
    {
        $tag = self::CACHE_TAG_PREFIX . $userId;
        $baseKey = self::CACHE_KEY_PREFIX . $userId;
        $key = $this->versionedKey($tag, $baseKey);

        return (float) $this->tagRemember($tag, $key, self::TTL, function () use ($userId) {
            $wallet = Wallet::where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            return $wallet ? (float) $wallet->balance : 0.0;
        });
    }

    /**
     * Invalidate wallet cache for a specific user via version bump.
     */
    public function clear(int $userId): void
    {
        $tag = self::CACHE_TAG_PREFIX . $userId;

        $this->bumpVersion($tag);
    }
}
