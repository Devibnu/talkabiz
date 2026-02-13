<?php

namespace App\Services;

use App\Models\Klien;
use App\Models\SubscriptionPlan;
use App\Models\WaUsageLog;
use App\Models\Kampanye;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * LimitService - Mengelola SEMUA limit pengiriman WA
 * 
 * ANTI SPAM + ANTI BONCOS
 * 
 * Cek yang dilakukan:
 * 1. Daily Limit - Batas kirim per hari
 * 2. Monthly Limit - Batas kirim per bulan
 * 3. Campaign Limit - Batas campaign aktif
 * 4. Feature Check - Fitur diizinkan di plan?
 * 
 * ATURAN KERAS:
 * - SEMUA pengiriman HARUS melewati cek limit
 * - Jika limit tercapai, STOP kirim
 * - Log semua rejection
 * 
 * @package App\Services
 */
class LimitService
{
    // Cache TTL (5 menit)
    const CACHE_TTL = 300;

    // Warning thresholds
    const WARNING_THRESHOLD = 0.80; // 80%
    const DANGER_THRESHOLD = 0.95;  // 95%

    /**
     * Check semua limit sebelum kirim
     * 
     * @param int $klienId
     * @param int $messageCount Jumlah pesan yang akan dikirim
     * @param string $feature campaign|inbox|broadcast
     * @return array{allowed: bool, reason?: string, code?: string, details: array}
     */
    public function checkAllLimits(int $klienId, int $messageCount = 1, string $feature = 'campaign'): array
    {
        // Get klien with plan
        $klien = Klien::with('subscriptionPlan')->find($klienId);
        
        if (!$klien) {
            return $this->reject('Klien tidak ditemukan', 'klien_not_found');
        }

        $plan = $klien->subscriptionPlan ?? SubscriptionPlan::getDefaultPlan();
        
        if (!$plan) {
            return $this->reject('Plan tidak ditemukan', 'plan_not_found');
        }

        // 1. Check feature enabled
        $featureCheck = $this->checkFeatureEnabled($plan, $feature);
        if (!$featureCheck['allowed']) {
            return $featureCheck;
        }

        // 2. Check daily limit
        $dailyCheck = $this->checkDailyLimit($klienId, $plan, $messageCount);
        if (!$dailyCheck['allowed']) {
            return $dailyCheck;
        }

        // 3. Check monthly limit
        $monthlyCheck = $this->checkMonthlyLimit($klienId, $plan, $messageCount);
        if (!$monthlyCheck['allowed']) {
            return $monthlyCheck;
        }

        // All checks passed
        return [
            'allowed' => true,
            'details' => [
                'daily' => $dailyCheck['details'],
                'monthly' => $monthlyCheck['details'],
                'plan' => $plan->name,
            ],
        ];
    }

    /**
     * Check if feature is enabled in plan
     */
    public function checkFeatureEnabled(SubscriptionPlan $plan, string $feature): array
    {
        $featureMap = [
            'campaign' => 'campaign_enabled',
            'broadcast' => 'broadcast_enabled',
            'inbox' => 'inbox_enabled',
            'template' => 'template_enabled',
            'api' => 'api_access_enabled',
        ];

        $field = $featureMap[$feature] ?? null;

        if (!$field || !$plan->$field) {
            return $this->reject(
                "Fitur {$feature} tidak tersedia di plan {$plan->display_name}",
                WaUsageLog::REJECTION_PLAN_NOT_ALLOWED,
                ['plan' => $plan->name, 'feature' => $feature]
            );
        }

        return ['allowed' => true];
    }

    /**
     * Check daily limit
     */
    public function checkDailyLimit(int $klienId, SubscriptionPlan $plan, int $messageCount = 1): array
    {
        // Unlimited?
        if ($plan->hasUnlimitedDaily()) {
            return [
                'allowed' => true,
                'details' => [
                    'used' => $this->getDailyUsage($klienId),
                    'limit' => 0,
                    'remaining' => PHP_INT_MAX,
                    'unlimited' => true,
                ],
            ];
        }

        $used = $this->getDailyUsage($klienId);
        $limit = $plan->max_daily_send;
        $remaining = max(0, $limit - $used);

        if ($remaining < $messageCount) {
            return $this->reject(
                "Limit harian tercapai. Tersisa {$remaining} dari {$limit} pesan.",
                WaUsageLog::REJECTION_LIMIT_DAILY,
                [
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => $remaining,
                    'requested' => $messageCount,
                ]
            );
        }

        return [
            'allowed' => true,
            'details' => [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'percentage' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
                'warning_level' => $this->getWarningLevel($used, $limit),
            ],
        ];
    }

    /**
     * Check monthly limit
     */
    public function checkMonthlyLimit(int $klienId, SubscriptionPlan $plan, int $messageCount = 1): array
    {
        // Unlimited?
        if ($plan->hasUnlimitedMonthly()) {
            return [
                'allowed' => true,
                'details' => [
                    'used' => $this->getMonthlyUsage($klienId),
                    'limit' => 0,
                    'remaining' => PHP_INT_MAX,
                    'unlimited' => true,
                ],
            ];
        }

        $used = $this->getMonthlyUsage($klienId);
        $limit = $plan->max_monthly_send;
        $remaining = max(0, $limit - $used);

        if ($remaining < $messageCount) {
            return $this->reject(
                "Limit bulanan tercapai. Tersisa {$remaining} dari {$limit} pesan.",
                WaUsageLog::REJECTION_LIMIT_MONTHLY,
                [
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => $remaining,
                    'requested' => $messageCount,
                ]
            );
        }

        return [
            'allowed' => true,
            'details' => [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'percentage' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
                'warning_level' => $this->getWarningLevel($used, $limit),
            ],
        ];
    }

    /**
     * Check campaign limit (jumlah campaign aktif)
     */
    public function checkCampaignLimit(int $klienId): array
    {
        $klien = Klien::with('subscriptionPlan')->find($klienId);
        $plan = $klien?->subscriptionPlan ?? SubscriptionPlan::getDefaultPlan();

        if (!$plan) {
            return $this->reject('Plan tidak ditemukan', 'plan_not_found');
        }

        // Unlimited?
        if ($plan->max_active_campaign === 0) {
            return [
                'allowed' => true,
                'details' => [
                    'active' => $this->getActiveCampaignCount($klienId),
                    'limit' => 0,
                    'unlimited' => true,
                ],
            ];
        }

        $active = $this->getActiveCampaignCount($klienId);
        $limit = $plan->max_active_campaign;

        if ($active >= $limit) {
            return $this->reject(
                "Limit campaign tercapai. Maksimal {$limit} campaign aktif.",
                WaUsageLog::REJECTION_CAMPAIGN_LIMIT,
                ['active' => $active, 'limit' => $limit]
            );
        }

        return [
            'allowed' => true,
            'details' => [
                'active' => $active,
                'limit' => $limit,
                'remaining' => $limit - $active,
            ],
        ];
    }

    /**
     * Get usage summary for klien
     * Untuk tampilan di UI
     */
    public function getUsageSummary(int $klienId): array
    {
        $klien = Klien::with('subscriptionPlan')->find($klienId);
        $plan = $klien?->subscriptionPlan ?? SubscriptionPlan::getDefaultPlan();

        $dailyUsed = $this->getDailyUsage($klienId);
        $monthlyUsed = $this->getMonthlyUsage($klienId);
        $activeCampaigns = $this->getActiveCampaignCount($klienId);

        return [
            'plan' => [
                'name' => $plan?->name ?? 'free',
                'display_name' => $plan?->display_name ?? 'Free',
            ],
            'daily' => [
                'used' => $dailyUsed,
                'limit' => $plan?->max_daily_send ?? 0,
                'remaining' => $plan?->hasUnlimitedDaily() 
                    ? PHP_INT_MAX 
                    : max(0, ($plan?->max_daily_send ?? 0) - $dailyUsed),
                'unlimited' => $plan?->hasUnlimitedDaily() ?? false,
                'percentage' => $this->getPercentage($dailyUsed, $plan?->max_daily_send ?? 0),
                'warning_level' => $this->getWarningLevel($dailyUsed, $plan?->max_daily_send ?? 0),
            ],
            'monthly' => [
                'used' => $monthlyUsed,
                'limit' => $plan?->max_monthly_send ?? 0,
                'remaining' => $plan?->hasUnlimitedMonthly() 
                    ? PHP_INT_MAX 
                    : max(0, ($plan?->max_monthly_send ?? 0) - $monthlyUsed),
                'unlimited' => $plan?->hasUnlimitedMonthly() ?? false,
                'percentage' => $this->getPercentage($monthlyUsed, $plan?->max_monthly_send ?? 0),
                'warning_level' => $this->getWarningLevel($monthlyUsed, $plan?->max_monthly_send ?? 0),
            ],
            'campaigns' => [
                'active' => $activeCampaigns,
                'limit' => $plan?->max_active_campaign ?? 0,
                'unlimited' => ($plan?->max_active_campaign ?? 0) === 0,
            ],
        ];
    }

    // ==================== PRIVATE HELPERS ====================

    private function getDailyUsage(int $klienId): int
    {
        $cacheKey = "usage_daily_{$klienId}_" . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($klienId) {
            return WaUsageLog::getDailyUsage($klienId);
        });
    }

    private function getMonthlyUsage(int $klienId): int
    {
        $cacheKey = "usage_monthly_{$klienId}_" . now()->format('Y-m');
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($klienId) {
            return WaUsageLog::getMonthlyUsage($klienId);
        });
    }

    private function getActiveCampaignCount(int $klienId): int
    {
        return Kampanye::where('klien_id', $klienId)
            ->whereIn('status', ['berjalan', 'siap'])
            ->count();
    }

    private function getPercentage(int $used, int $limit): float
    {
        if ($limit === 0) {
            return 0;
        }
        return round(($used / $limit) * 100, 1);
    }

    private function getWarningLevel(int $used, int $limit): string
    {
        if ($limit === 0) {
            return 'none'; // Unlimited
        }

        $percentage = $used / $limit;

        if ($percentage >= self::DANGER_THRESHOLD) {
            return 'danger';
        }

        if ($percentage >= self::WARNING_THRESHOLD) {
            return 'warning';
        }

        return 'none';
    }

    private function reject(string $message, string $code, array $details = []): array
    {
        Log::warning('LimitService: Limit reached', [
            'message' => $message,
            'code' => $code,
            'details' => $details,
        ]);

        return [
            'allowed' => false,
            'reason' => $message,
            'code' => $code,
            'details' => $details,
        ];
    }

    /**
     * Invalidate usage cache after message sent
     */
    public function invalidateCache(int $klienId): void
    {
        $dailyKey = "usage_daily_{$klienId}_" . now()->format('Y-m-d');
        $monthlyKey = "usage_monthly_{$klienId}_" . now()->format('Y-m');
        
        Cache::forget($dailyKey);
        Cache::forget($monthlyKey);
    }
}
