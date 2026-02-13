<?php

namespace App\Services;

use App\Models\User;
use App\Models\Klien;
use App\Models\Subscription;
use App\Exceptions\Subscription\NoActiveSubscriptionException;
use App\Exceptions\Subscription\FeatureNotAllowedException;
use App\Exceptions\Subscription\QuotaExceededException;
use App\Exceptions\Subscription\SubscriptionExpiredException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SubscriptionGate Service
 * 
 * Subscription-based access control using SNAPSHOT data (immutable).
 * 
 * CRITICAL RULES:
 * 1. NEVER read from plans table at runtime
 * 2. ALWAYS use subscription.plan_snapshot
 * 3. Snapshot is IMMUTABLE - represents what user paid for
 * 
 * @see SA Document: Modul Paket / Subscription Plan - Phase 4
 */
class SubscriptionGate
{
    // Cache TTL for subscription data (5 minutes)
    private const CACHE_TTL = 300;

    /**
     * Get active subscription for user
     * 
     * @param User $user
     * @return Subscription|null
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        if (!$user->klien_id) {
            return null;
        }

        $cacheKey = "subscription:active:{$user->klien_id}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return Subscription::where('klien_id', $user->klien_id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->first();
        });
    }

    /**
     * Require active subscription or throw exception
     * 
     * @param User $user
     * @return Subscription
     * @throws NoActiveSubscriptionException
     * @throws SubscriptionExpiredException
     */
    public function requireActiveSubscription(User $user): Subscription
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            throw new NoActiveSubscriptionException($user->id);
        }

        // Check expiration
        if ($subscription->is_expired) {
            throw new SubscriptionExpiredException(
                $subscription->expires_at,
                $subscription->plan_name
            );
        }

        return $subscription;
    }

    /**
     * Check if user has access to a feature
     * 
     * Uses SNAPSHOT features array (immutable)
     * 
     * @param User $user
     * @param string $feature Feature key (e.g., 'broadcast', 'api_access')
     * @return bool
     */
    public function hasFeature(User $user, string $feature): bool
    {
        $subscription = $this->getActiveSubscription($user);
        
        if (!$subscription) {
            return false;
        }

        $features = $subscription->features; // From snapshot via accessor
        
        return in_array($feature, $features, true);
    }

    /**
     * Require access to a feature or throw exception
     * 
     * @param User $user
     * @param string $feature
     * @throws NoActiveSubscriptionException
     * @throws FeatureNotAllowedException
     */
    public function requireFeature(User $user, string $feature): void
    {
        $subscription = $this->requireActiveSubscription($user);

        if (!$this->hasFeature($user, $feature)) {
            throw new FeatureNotAllowedException($feature, $subscription->plan_name);
        }
    }

    /**
     * Get limit value from snapshot
     * 
     * @param User $user
     * @param string $limitType Limit key (e.g., 'limit_messages_monthly')
     * @return int|null Null means unlimited
     */
    public function getLimit(User $user, string $limitType): ?int
    {
        $subscription = $this->getActiveSubscription($user);
        
        if (!$subscription) {
            return 0; // No subscription = no quota
        }

        $snapshot = $subscription->plan_snapshot ?? [];
        
        // Map common limit keys
        $limitKey = match($limitType) {
            'messages', 'messages_monthly' => 'limit_messages_monthly',
            'wa_numbers' => 'limit_wa_numbers',
            'contacts' => 'limit_contacts',
            'templates' => 'limit_templates',
            'campaigns_daily' => 'limit_campaigns_daily',
            default => $limitType,
        };

        return $snapshot[$limitKey] ?? null; // null = unlimited
    }

    /**
     * Check if usage is within limit
     * 
     * @param User $user
     * @param string $limitType
     * @param int $currentUsage
     * @param int $requestedAmount Amount to add (default 1)
     * @return bool True if within limit
     */
    public function checkLimit(User $user, string $limitType, int $currentUsage, int $requestedAmount = 1): bool
    {
        $limit = $this->getLimit($user, $limitType);

        // null = unlimited
        if ($limit === null) {
            return true;
        }

        return ($currentUsage + $requestedAmount) <= $limit;
    }

    /**
     * Require usage within limit or throw exception
     * 
     * @param User $user
     * @param string $limitType
     * @param int $currentUsage
     * @param int $requestedAmount
     * @throws NoActiveSubscriptionException
     * @throws QuotaExceededException
     */
    public function requireWithinLimit(User $user, string $limitType, int $currentUsage, int $requestedAmount = 1): void
    {
        $this->requireActiveSubscription($user);

        if (!$this->checkLimit($user, $limitType, $currentUsage, $requestedAmount)) {
            $limit = $this->getLimit($user, $limitType);
            throw new QuotaExceededException($limitType, $currentUsage, $limit, $requestedAmount);
        }
    }

    /**
     * Get remaining quota
     * 
     * @param User $user
     * @param string $limitType
     * @param int $currentUsage
     * @return int|null Null means unlimited
     */
    public function getRemainingQuota(User $user, string $limitType, int $currentUsage): ?int
    {
        $limit = $this->getLimit($user, $limitType);

        if ($limit === null) {
            return null; // Unlimited
        }

        return max(0, $limit - $currentUsage);
    }

    /**
     * Check multiple features at once
     * 
     * @param User $user
     * @param array $features
     * @return array<string, bool>
     */
    public function checkFeatures(User $user, array $features): array
    {
        $result = [];
        foreach ($features as $feature) {
            $result[$feature] = $this->hasFeature($user, $feature);
        }
        return $result;
    }

    /**
     * Get full subscription info for UI display
     * 
     * @param User $user
     * @return array
     */
    public function getSubscriptionInfo(User $user): array
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return [
                'has_subscription' => false,
                'plan_name' => null,
                'plan_code' => null,
                'status' => 'none',
                'expires_at' => null,
                'features' => [],
                'limits' => [],
            ];
        }

        $snapshot = $subscription->plan_snapshot ?? [];

        return [
            'has_subscription' => true,
            'plan_name' => $subscription->plan_name,
            'plan_code' => $subscription->plan_code,
            'status' => $subscription->status,
            'started_at' => $subscription->started_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'is_expired' => $subscription->is_expired,
            'features' => $subscription->features,
            'limits' => [
                'messages_monthly' => $snapshot['limit_messages_monthly'] ?? null,
                'wa_numbers' => $snapshot['limit_wa_numbers'] ?? null,
                'contacts' => $snapshot['limit_contacts'] ?? null,
                'templates' => $snapshot['limit_templates'] ?? null,
                'campaigns_daily' => $snapshot['limit_campaigns_daily'] ?? null,
            ],
        ];
    }

    /**
     * Invalidate subscription cache for user
     * 
     * Call this after subscription changes (upgrade, renewal, etc.)
     * 
     * @param int $klienId
     */
    public function invalidateCache(int $klienId): void
    {
        Cache::forget("subscription:active:{$klienId}");
        
        Log::info('Subscription cache invalidated', ['klien_id' => $klienId]);
    }

    /**
     * Check if user can perform broadcast
     * 
     * Convenience method combining feature + limit check
     * 
     * @param User $user
     * @param int $recipientCount Number of recipients
     * @param int $currentMonthlyUsage Current month message count
     * @return bool
     */
    public function canBroadcast(User $user, int $recipientCount, int $currentMonthlyUsage): bool
    {
        // Must have broadcast feature
        if (!$this->hasFeature($user, 'broadcast')) {
            return false;
        }

        // Must be within message limit
        return $this->checkLimit($user, 'messages_monthly', $currentMonthlyUsage, $recipientCount);
    }

    /**
     * Require broadcast capability or throw exception
     * 
     * @param User $user
     * @param int $recipientCount
     * @param int $currentMonthlyUsage
     * @throws FeatureNotAllowedException
     * @throws QuotaExceededException
     */
    public function requireBroadcastCapability(User $user, int $recipientCount, int $currentMonthlyUsage): void
    {
        $this->requireFeature($user, 'broadcast');
        $this->requireWithinLimit($user, 'messages_monthly', $currentMonthlyUsage, $recipientCount);
    }
}
