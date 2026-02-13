<?php

namespace App\Services;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SubscriptionPolicy Service
 * 
 * SINGLE SOURCE OF TRUTH untuk semua subscription enforcement.
 * 
 * CRITICAL RULES:
 * ===============
 * 1. SEMUA validasi HARUS membaca dari subscriptions.plan_snapshot
 * 2. DILARANG membaca table plans saat runtime
 * 3. Return JSON terstruktur (bukan exception)
 * 4. Reason codes: no_subscription | subscription_expired | feature_disabled | limit_exceeded
 * 
 * USAGE:
 * ======
 * $policy = app(SubscriptionPolicy::class);
 * $result = $policy->canSendMessage($user, 10);
 * if (!$result['allowed']) {
 *     return response()->json($result, 403);
 * }
 * 
 * @see SA Document: Subscription Enforcement Core
 * @author Senior Laravel SaaS Architect
 */
class SubscriptionPolicy
{
    // Cache TTL (5 minutes)
    private const CACHE_TTL = 300;

    // ==================== REASON CODES ====================
    public const REASON_NO_SUBSCRIPTION = 'no_subscription';
    public const REASON_SUBSCRIPTION_EXPIRED = 'subscription_expired';
    public const REASON_FEATURE_DISABLED = 'feature_disabled';
    public const REASON_LIMIT_EXCEEDED = 'limit_exceeded';
    public const REASON_ALLOWED = 'allowed';

    // ==================== FEATURE KEYS ====================
    public const FEATURE_BROADCAST = 'broadcast';
    public const FEATURE_API_ACCESS = 'api_access';
    public const FEATURE_WEBHOOK = 'webhook';
    public const FEATURE_ANALYTICS = 'analytics';
    public const FEATURE_MULTI_AGENT = 'multi_agent';
    public const FEATURE_CUSTOM_DOMAIN = 'custom_domain';

    // ==================== LIMIT KEYS ====================
    public const LIMIT_MESSAGES_MONTHLY = 'limit_messages_monthly';
    public const LIMIT_MESSAGES_DAILY = 'limit_messages_daily';
    public const LIMIT_MESSAGES_HOURLY = 'limit_messages_hourly';
    public const LIMIT_WA_NUMBERS = 'limit_wa_numbers';
    public const LIMIT_CONTACTS = 'limit_contacts';
    public const LIMIT_TEMPLATES = 'limit_templates';
    public const LIMIT_ACTIVE_CAMPAIGNS = 'limit_active_campaigns';
    public const LIMIT_RECIPIENTS_PER_CAMPAIGN = 'limit_recipients_per_campaign';

    // ==================== CORE METHODS ====================

    /**
     * Get active subscription from snapshot (cached)
     * 
     * NEVER reads from plans table - only from subscription.plan_snapshot
     * 
     * @param User $user
     * @return Subscription|null
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        if (!$user->klien_id) {
            return null;
        }

        $cacheKey = "subscription:policy:{$user->klien_id}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return Subscription::where('klien_id', $user->klien_id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->first();
        });
    }

    /**
     * Validate user has active subscription
     * 
     * @param User $user
     * @return array{allowed: bool, reason: string, message: string, subscription?: Subscription}
     */
    public function validateSubscription(User $user): array
    {
        // Skip for admin/owner roles
        if ($this->isAdminRole($user)) {
            return $this->allow('Admin memiliki akses penuh');
        }

        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return $this->deny(
                self::REASON_NO_SUBSCRIPTION,
                'Anda belum memiliki paket aktif. Silakan pilih paket terlebih dahulu.'
            );
        }

        // Check expiration from snapshot/model
        if ($subscription->is_expired) {
            return $this->deny(
                self::REASON_SUBSCRIPTION_EXPIRED,
                'Paket Anda sudah berakhir. Perpanjang paket untuk melanjutkan.'
            );
        }

        return $this->allow('Subscription aktif', $subscription);
    }

    /**
     * Check if user has access to a feature
     * 
     * Reads from subscription.plan_snapshot['features']
     * 
     * @param User $user
     * @param string $feature Feature key
     * @return array{allowed: bool, reason: string, message: string, feature?: string}
     */
    public function canAccessFeature(User $user, string $feature): array
    {
        // Skip for admin/owner roles
        if ($this->isAdminRole($user)) {
            return $this->allow("Admin akses fitur {$feature}");
        }

        // First validate subscription
        $subscriptionCheck = $this->validateSubscription($user);
        if (!$subscriptionCheck['allowed']) {
            return $subscriptionCheck;
        }

        $subscription = $subscriptionCheck['subscription'];
        $features = $subscription->plan_snapshot['features'] ?? [];

        if (!in_array($feature, $features, true)) {
            $planName = $subscription->plan_snapshot['name'] ?? 'Paket Anda';
            return $this->deny(
                self::REASON_FEATURE_DISABLED,
                "Fitur ini tidak tersedia di {$planName}. Upgrade untuk mengakses fitur ini.",
                ['feature' => $feature, 'plan_name' => $planName]
            );
        }

        return $this->allow("Fitur {$feature} tersedia");
    }

    /**
     * Check if user can send messages (quota check)
     * 
     * Validates against subscription.plan_snapshot limits
     * 
     * @param User $user
     * @param int $messageCount Number of messages to send
     * @return array{allowed: bool, reason: string, message: string, limit?: int, used?: int, remaining?: int}
     */
    public function canSendMessage(User $user, int $messageCount = 1): array
    {
        // Skip for admin/owner roles
        if ($this->isAdminRole($user)) {
            return $this->allow('Admin dapat mengirim tanpa batas');
        }

        // First validate subscription
        $subscriptionCheck = $this->validateSubscription($user);
        if (!$subscriptionCheck['allowed']) {
            return $subscriptionCheck;
        }

        $subscription = $subscriptionCheck['subscription'];
        $snapshot = $subscription->plan_snapshot ?? [];

        // Get limits from snapshot
        $monthlyLimit = $snapshot[self::LIMIT_MESSAGES_MONTHLY] ?? null;
        $dailyLimit = $snapshot[self::LIMIT_MESSAGES_DAILY] ?? null;
        $hourlyLimit = $snapshot[self::LIMIT_MESSAGES_HOURLY] ?? null;

        // Get current usage from user
        $monthlyUsed = $user->messages_sent_monthly ?? 0;
        $dailyUsed = $user->messages_sent_daily ?? 0;
        $hourlyUsed = $user->messages_sent_hourly ?? 0;

        // Check monthly limit
        if ($monthlyLimit !== null && $monthlyLimit > 0) {
            if (($monthlyUsed + $messageCount) > $monthlyLimit) {
                return $this->deny(
                    self::REASON_LIMIT_EXCEEDED,
                    "Kuota pesan bulanan habis ({$monthlyUsed}/{$monthlyLimit}). Upgrade paket untuk kuota lebih besar.",
                    [
                        'limit_type' => 'monthly',
                        'limit' => $monthlyLimit,
                        'used' => $monthlyUsed,
                        'remaining' => max(0, $monthlyLimit - $monthlyUsed),
                        'requested' => $messageCount,
                    ]
                );
            }
        }

        // Check daily limit
        if ($dailyLimit !== null && $dailyLimit > 0) {
            if (($dailyUsed + $messageCount) > $dailyLimit) {
                return $this->deny(
                    self::REASON_LIMIT_EXCEEDED,
                    "Batas harian tercapai ({$dailyUsed}/{$dailyLimit}). Coba lagi besok atau upgrade paket.",
                    [
                        'limit_type' => 'daily',
                        'limit' => $dailyLimit,
                        'used' => $dailyUsed,
                        'remaining' => max(0, $dailyLimit - $dailyUsed),
                        'requested' => $messageCount,
                    ]
                );
            }
        }

        // Check hourly limit
        if ($hourlyLimit !== null && $hourlyLimit > 0) {
            if (($hourlyUsed + $messageCount) > $hourlyLimit) {
                return $this->deny(
                    self::REASON_LIMIT_EXCEEDED,
                    "Batas per jam tercapai ({$hourlyUsed}/{$hourlyLimit}). Tunggu sebentar atau upgrade paket.",
                    [
                        'limit_type' => 'hourly',
                        'limit' => $hourlyLimit,
                        'used' => $hourlyUsed,
                        'remaining' => max(0, $hourlyLimit - $hourlyUsed),
                        'requested' => $messageCount,
                    ]
                );
            }
        }

        return $this->allow('Kuota tersedia', null, [
            'monthly' => [
                'limit' => $monthlyLimit,
                'used' => $monthlyUsed,
                'remaining' => $monthlyLimit ? max(0, $monthlyLimit - $monthlyUsed) : null,
            ],
            'daily' => [
                'limit' => $dailyLimit,
                'used' => $dailyUsed,
                'remaining' => $dailyLimit ? max(0, $dailyLimit - $dailyUsed) : null,
            ],
            'hourly' => [
                'limit' => $hourlyLimit,
                'used' => $hourlyUsed,
                'remaining' => $hourlyLimit ? max(0, $hourlyLimit - $hourlyUsed) : null,
            ],
        ]);
    }

    /**
     * Check if user can add more WhatsApp numbers
     * 
     * @param User $user
     * @param int $currentCount Current connected WA numbers
     * @return array
     */
    public function canAddWaNumber(User $user, int $currentCount = 0): array
    {
        // Skip for admin/owner roles
        if ($this->isAdminRole($user)) {
            return $this->allow('Admin dapat menambah WA tanpa batas');
        }

        // First validate subscription
        $subscriptionCheck = $this->validateSubscription($user);
        if (!$subscriptionCheck['allowed']) {
            return $subscriptionCheck;
        }

        $subscription = $subscriptionCheck['subscription'];
        $snapshot = $subscription->plan_snapshot ?? [];

        $waLimit = $snapshot[self::LIMIT_WA_NUMBERS] ?? null;

        // Null or 0 means unlimited
        if ($waLimit === null || $waLimit === 0) {
            return $this->allow('Unlimited WA numbers');
        }

        if ($currentCount >= $waLimit) {
            $planName = $snapshot['name'] ?? 'Paket Anda';
            return $this->deny(
                self::REASON_LIMIT_EXCEEDED,
                "Batas nomor WhatsApp tercapai ({$currentCount}/{$waLimit}). Upgrade untuk menambah nomor.",
                [
                    'limit_type' => 'wa_numbers',
                    'limit' => $waLimit,
                    'current' => $currentCount,
                    'plan_name' => $planName,
                ]
            );
        }

        return $this->allow('Dapat menambah nomor WA', null, [
            'limit' => $waLimit,
            'current' => $currentCount,
            'remaining' => $waLimit - $currentCount,
        ]);
    }

    /**
     * Check if user can create campaign (broadcast check)
     * 
     * Combines feature check + quota check
     * 
     * @param User $user
     * @param int $recipientCount Number of recipients
     * @return array
     */
    public function canCreateCampaign(User $user, int $recipientCount): array
    {
        // Skip for admin/owner roles
        if ($this->isAdminRole($user)) {
            return $this->allow('Admin dapat membuat campaign tanpa batas');
        }

        // 1. Check broadcast feature
        $featureCheck = $this->canAccessFeature($user, self::FEATURE_BROADCAST);
        if (!$featureCheck['allowed']) {
            return $featureCheck;
        }

        // 2. Check message quota
        $quotaCheck = $this->canSendMessage($user, $recipientCount);
        if (!$quotaCheck['allowed']) {
            return $quotaCheck;
        }

        // 3. Check recipients per campaign limit
        $subscription = $this->getActiveSubscription($user);
        $snapshot = $subscription->plan_snapshot ?? [];
        $recipientLimit = $snapshot[self::LIMIT_RECIPIENTS_PER_CAMPAIGN] ?? null;

        if ($recipientLimit !== null && $recipientLimit > 0 && $recipientCount > $recipientLimit) {
            return $this->deny(
                self::REASON_LIMIT_EXCEEDED,
                "Maksimal {$recipientLimit} penerima per campaign. Kurangi penerima atau upgrade paket.",
                [
                    'limit_type' => 'recipients_per_campaign',
                    'limit' => $recipientLimit,
                    'requested' => $recipientCount,
                ]
            );
        }

        // 4. Check active campaigns limit
        $activeCampaignsLimit = $snapshot[self::LIMIT_ACTIVE_CAMPAIGNS] ?? null;
        if ($activeCampaignsLimit !== null && $activeCampaignsLimit > 0) {
            $activeCampaigns = $user->active_campaigns_count ?? 0;
            if ($activeCampaigns >= $activeCampaignsLimit) {
                return $this->deny(
                    self::REASON_LIMIT_EXCEEDED,
                    "Batas campaign aktif tercapai ({$activeCampaigns}/{$activeCampaignsLimit}). Selesaikan campaign yang ada.",
                    [
                        'limit_type' => 'active_campaigns',
                        'limit' => $activeCampaignsLimit,
                        'current' => $activeCampaigns,
                    ]
                );
            }
        }

        return $this->allow('Campaign dapat dibuat', null, [
            'recipient_count' => $recipientCount,
            'quota' => $quotaCheck['data'] ?? [],
        ]);
    }

    /**
     * Get limit value from snapshot
     * 
     * @param User $user
     * @param string $limitKey
     * @return int|null Null means unlimited
     */
    public function getLimit(User $user, string $limitKey): ?int
    {
        if ($this->isAdminRole($user)) {
            return null; // Unlimited for admin
        }

        $subscription = $this->getActiveSubscription($user);
        if (!$subscription) {
            return 0; // No subscription = no quota
        }

        return $subscription->plan_snapshot[$limitKey] ?? null;
    }

    /**
     * Get subscription info for display
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
            'plan_name' => $snapshot['name'] ?? 'Unknown',
            'plan_code' => $snapshot['code'] ?? 'unknown',
            'status' => $subscription->status,
            'started_at' => $subscription->started_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'is_expired' => $subscription->is_expired,
            'features' => $snapshot['features'] ?? [],
            'limits' => [
                'messages_monthly' => $snapshot[self::LIMIT_MESSAGES_MONTHLY] ?? null,
                'messages_daily' => $snapshot[self::LIMIT_MESSAGES_DAILY] ?? null,
                'messages_hourly' => $snapshot[self::LIMIT_MESSAGES_HOURLY] ?? null,
                'wa_numbers' => $snapshot[self::LIMIT_WA_NUMBERS] ?? null,
                'contacts' => $snapshot[self::LIMIT_CONTACTS] ?? null,
                'templates' => $snapshot[self::LIMIT_TEMPLATES] ?? null,
                'active_campaigns' => $snapshot[self::LIMIT_ACTIVE_CAMPAIGNS] ?? null,
                'recipients_per_campaign' => $snapshot[self::LIMIT_RECIPIENTS_PER_CAMPAIGN] ?? null,
            ],
        ];
    }

    /**
     * Invalidate cache for user
     * 
     * Call after subscription changes
     * 
     * @param int $klienId
     */
    public function invalidateCache(int $klienId): void
    {
        Cache::forget("subscription:policy:{$klienId}");
        Cache::forget("subscription:active:{$klienId}");

        Log::info('SubscriptionPolicy cache invalidated', ['klien_id' => $klienId]);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if user has admin role (bypass all checks)
     */
    protected function isAdminRole(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'superadmin', 'owner'], true);
    }

    /**
     * Build allow response
     */
    protected function allow(string $message, ?Subscription $subscription = null, array $data = []): array
    {
        return array_merge([
            'allowed' => true,
            'reason' => self::REASON_ALLOWED,
            'message' => $message,
        ], $subscription ? ['subscription' => $subscription] : [], $data ? ['data' => $data] : []);
    }

    /**
     * Build deny response
     */
    protected function deny(string $reason, string $message, array $data = []): array
    {
        Log::info('SubscriptionPolicy: Access denied', [
            'reason' => $reason,
            'message' => $message,
            'data' => $data,
        ]);

        return array_merge([
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'upgrade_url' => route('subscription.index'),
        ], $data ? ['data' => $data] : []);
    }
}
