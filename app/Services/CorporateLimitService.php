<?php

namespace App\Services;

use App\Models\CorporateClient;
use App\Models\User;

/**
 * Corporate Limit Service
 * 
 * Handle custom limits per corporate client:
 * - Override plan limits
 * - Check against custom thresholds
 * - Failsafe enforcement
 * 
 * PRIORITY ORDER:
 * 1. Corporate custom limit (if set)
 * 2. Plan limit (fallback)
 * 3. HARDCAP (ultimate protection)
 */
class CorporateLimitService
{
    // Default corporate limits (more generous than Growth)
    const DEFAULT_LIMITS = [
        'messages_monthly' => 50000,
        'messages_daily' => 5000,
        'messages_hourly' => 1000,
        'wa_numbers' => 20,
        'active_campaigns' => 50,
        'recipients_per_campaign' => 10000,
    ];

    // HARDCAP - absolute maximum (protection)
    const HARDCAP = [
        'messages_monthly' => 500000,
        'messages_daily' => 50000,
        'messages_hourly' => 10000,
        'wa_numbers' => 100,
        'active_campaigns' => 200,
        'recipients_per_campaign' => 100000,
    ];

    /**
     * Get effective limit for a corporate client.
     */
    public function getLimit(CorporateClient $client, string $limitType): int
    {
        $columnName = "limit_{$limitType}";
        
        // Priority 1: Custom limit if set
        $customLimit = $client->$columnName;
        if ($customLimit !== null) {
            // Enforce HARDCAP
            return min($customLimit, self::HARDCAP[$limitType] ?? PHP_INT_MAX);
        }

        // Priority 2: Default corporate limit
        return self::DEFAULT_LIMITS[$limitType] ?? 0;
    }

    /**
     * Get all effective limits for a client.
     */
    public function getAllLimits(CorporateClient $client): array
    {
        return [
            'messages_monthly' => $this->getLimit($client, 'messages_monthly'),
            'messages_daily' => $this->getLimit($client, 'messages_daily'),
            'messages_hourly' => $this->getLimit($client, 'messages_hourly'),
            'wa_numbers' => $this->getLimit($client, 'wa_numbers'),
            'active_campaigns' => $this->getLimit($client, 'active_campaigns'),
            'recipients_per_campaign' => $this->getLimit($client, 'recipients_per_campaign'),
        ];
    }

    /**
     * Check if user is within limit.
     */
    public function isWithinLimit(User $user, string $limitType, int $currentUsage): bool
    {
        $client = $this->getClientForUser($user);
        
        if (!$client) {
            // Not a corporate user, use standard plan limits
            return true;
        }

        $limit = $this->getLimit($client, $limitType);
        
        return $currentUsage < $limit;
    }

    /**
     * Check if client can send more messages.
     */
    public function canSendMessages(CorporateClient $client, int $currentDailyUsage, int $currentHourlyUsage): array
    {
        // Check if client is paused or suspended
        if (!$client->canSendMessages()) {
            return [
                'allowed' => false,
                'reason' => $client->is_paused ? 'Client is paused' : 'Client cannot send messages',
            ];
        }

        // Check hourly limit
        $hourlyLimit = $this->getLimit($client, 'messages_hourly');
        if ($currentHourlyUsage >= $hourlyLimit) {
            return [
                'allowed' => false,
                'reason' => 'Hourly limit reached',
                'limit' => $hourlyLimit,
                'usage' => $currentHourlyUsage,
            ];
        }

        // Check daily limit
        $dailyLimit = $this->getLimit($client, 'messages_daily');
        if ($currentDailyUsage >= $dailyLimit) {
            return [
                'allowed' => false,
                'reason' => 'Daily limit reached',
                'limit' => $dailyLimit,
                'usage' => $currentDailyUsage,
            ];
        }

        return [
            'allowed' => true,
            'remaining_hourly' => $hourlyLimit - $currentHourlyUsage,
            'remaining_daily' => $dailyLimit - $currentDailyUsage,
        ];
    }

    /**
     * Get usage percentage.
     */
    public function getUsagePercentage(CorporateClient $client, string $limitType, int $currentUsage): float
    {
        $limit = $this->getLimit($client, $limitType);
        
        if ($limit === 0) {
            return 0;
        }

        return round(($currentUsage / $limit) * 100, 2);
    }

    /**
     * Get usage breakdown for dashboard.
     */
    public function getUsageBreakdown(CorporateClient $client, array $currentUsage): array
    {
        $limits = $this->getAllLimits($client);
        $breakdown = [];

        foreach ($limits as $type => $limit) {
            $usage = $currentUsage[$type] ?? 0;
            $percentage = $limit > 0 ? round(($usage / $limit) * 100, 2) : 0;
            
            $breakdown[$type] = [
                'current' => $usage,
                'limit' => $limit,
                'percentage' => $percentage,
                'remaining' => max(0, $limit - $usage),
                'status' => $this->getUsageStatus($percentage),
            ];
        }

        return $breakdown;
    }

    /**
     * Get status based on usage percentage.
     */
    protected function getUsageStatus(float $percentage): string
    {
        if ($percentage >= 100) {
            return 'exceeded';
        }
        if ($percentage >= 90) {
            return 'critical';
        }
        if ($percentage >= 70) {
            return 'warning';
        }
        return 'healthy';
    }

    /**
     * Update custom limits for a client.
     */
    public function updateLimits(CorporateClient $client, array $newLimits, int $adminId): void
    {
        $oldLimits = $this->getAllLimits($client);
        
        $updateData = [];
        foreach ($newLimits as $type => $value) {
            $columnName = "limit_{$type}";
            
            // Validate against HARDCAP
            if (isset(self::HARDCAP[$type]) && $value > self::HARDCAP[$type]) {
                throw new \Exception("Limit for {$type} exceeds HARDCAP of " . self::HARDCAP[$type]);
            }
            
            $updateData[$columnName] = $value;
        }

        $client->update($updateData);

        // Log the change
        $client->logActivity(
            'limit_changed',
            'limit',
            'Custom limits updated',
            $adminId,
            'admin',
            $oldLimits,
            $this->getAllLimits($client)
        );
    }

    /**
     * Reset to default limits.
     */
    public function resetToDefaults(CorporateClient $client, int $adminId): void
    {
        $oldLimits = $this->getAllLimits($client);

        $client->update([
            'limit_messages_monthly' => null,
            'limit_messages_daily' => null,
            'limit_messages_hourly' => null,
            'limit_wa_numbers' => null,
            'limit_active_campaigns' => null,
            'limit_recipients_per_campaign' => null,
        ]);

        $client->logActivity(
            'limit_changed',
            'limit',
            'Limits reset to defaults',
            $adminId,
            'admin',
            $oldLimits,
            self::DEFAULT_LIMITS
        );
    }

    /**
     * Get corporate client for a user.
     */
    protected function getClientForUser(User $user): ?CorporateClient
    {
        if (!$user->corporate_pilot) {
            return null;
        }

        return CorporateClient::where('user_id', $user->id)->first();
    }

    /**
     * Apply throttle rate to limit.
     */
    public function getThrottledLimit(CorporateClient $client, string $limitType): int
    {
        $baseLimit = $this->getLimit($client, $limitType);
        
        if (!$client->is_throttled) {
            return $baseLimit;
        }

        $throttleRate = $client->throttle_rate_percent ?? 100;
        
        return (int) floor($baseLimit * ($throttleRate / 100));
    }
}
