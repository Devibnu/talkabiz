<?php

namespace App\Services;

use App\Models\SupportChannel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Channel Access Service
 * 
 * Controls access to support channels based on user subscription package level.
 * Enforces strict business rules for channel availability and restrictions.
 * 
 * CRITICAL BUSINESS RULES:
 * - ✅ Package-based channel restrictions (NO bypassing)
 * - ✅ Business hours enforcement per channel
 * - ✅ Channel capacity and load management
 * - ✅ Emergency channel access control
 */
class ChannelAccessService
{
    // Cache keys for performance
    private const CACHE_PREFIX = 'channel_access:';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Check if user has access to specific channel
     * 
     * @param User $user
     * @param string $channelCode
     * @return bool
     */
    public function userHasAccess(User $user, string $channelCode): bool
    {
        $cacheKey = self::CACHE_PREFIX . "user:{$user->id}:channel:{$channelCode}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $channelCode) {
            $channel = $this->getChannelByCode($channelCode);
            
            if (!$channel) {
                return false;
            }

            return $this->validateChannelAccess($user, $channel);
        });
    }

    /**
     * Get all available channels for user
     * 
     * @param User $user
     * @param bool $includeBusinessHours
     * @return Collection
     */
    public function getAvailableChannels(User $user, bool $includeBusinessHours = true): Collection
    {
        $packageLevel = $this->getUserPackageLevel($user);
        $cacheKey = self::CACHE_PREFIX . "user_channels:{$user->id}:package:{$packageLevel}:bh:" . ($includeBusinessHours ? '1' : '0');
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $includeBusinessHours) {
            return SupportChannel::getAvailableChannelsForUser($user, $includeBusinessHours);
        });
    }

    /**
     * Get premium channels for enterprise users
     * 
     * @param User $user
     * @return Collection
     */
    public function getPremiumChannels(User $user): Collection
    {
        $packageLevel = $this->getUserPackageLevel($user);
        
        if ($packageLevel !== SupportChannel::PACKAGE_ENTERPRISE) {
            return collect();
        }

        $cacheKey = self::CACHE_PREFIX . "premium_channels:{$user->id}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return SupportChannel::getPremiumChannelsForUser($user);
        });
    }

    /**
     * Get emergency channels (always available for critical issues)
     * 
     * @param User $user
     * @return Collection
     */
    public function getEmergencyChannels(User $user): Collection
    {
        $cacheKey = self::CACHE_PREFIX . "emergency_channels:{$user->id}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return SupportChannel::getEmergencyChannelsForUser($user);
        });
    }

    /**
     * Check channel availability with load balancing
     * 
     * @param string $channelCode
     * @param User|null $user
     * @return array
     */
    public function getChannelAvailability(string $channelCode, ?User $user = null): array
    {
        $channel = $this->getChannelByCode($channelCode);
        
        if (!$channel) {
            return [
                'available' => false,
                'reason' => 'Channel not found',
                'wait_time' => null
            ];
        }

        // Check user access if provided
        if ($user && !$this->userHasAccess($user, $channelCode)) {
            return [
                'available' => false,
                'reason' => 'Access denied for your package level',
                'wait_time' => null
            ];
        }

        // Check channel availability
        if (!$channel->isCurrentlyAvailable($user)) {
            return [
                'available' => false,
                'reason' => $this->getUnavailabilityReason($channel),
                'wait_time' => null
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'wait_time' => $channel->estimated_wait_time_minutes,
            'load_status' => $channel->getLoadStatus(),
            'load_percentage' => $channel->load_percentage
        ];
    }

    /**
     * Validate channel access for user
     * 
     * @param User $user
     * @param SupportChannel $channel
     * @return bool
     */
    public function validateChannelAccess(User $user, SupportChannel $channel): bool
    {
        // Check if channel is active
        if (!$channel->is_active || !$channel->is_available) {
            Log::info('Channel access denied - channel inactive', [
                'user_id' => $user->id,
                'channel_code' => $channel->channel_code,
                'is_active' => $channel->is_active,
                'is_available' => $channel->is_available
            ]);
            return false;
        }

        // Check package level access
        if (!$this->validatePackageAccess($user, $channel)) {
            Log::info('Channel access denied - package level', [
                'user_id' => $user->id,
                'user_package' => $this->getUserPackageLevel($user),
                'channel_code' => $channel->channel_code,
                'channel_packages' => $channel->available_packages
            ]);
            return false;
        }

        // Check business hours
        if ($channel->is_business_hours_only && !$this->isBusinessHours()) {
            Log::info('Channel access denied - business hours', [
                'user_id' => $user->id,
                'channel_code' => $channel->channel_code,
                'current_time' => now()->toISOString()
            ]);
            return false;
        }

        // Check authentication requirement
        if ($channel->requires_authentication && !$user) {
            Log::info('Channel access denied - authentication required', [
                'channel_code' => $channel->channel_code
            ]);
            return false;
        }

        // Check capacity
        if (!$this->validateChannelCapacity($channel)) {
            Log::info('Channel access denied - at capacity', [
                'user_id' => $user->id,
                'channel_code' => $channel->channel_code,
                'current_load' => $channel->current_load,
                'capacity_limit' => $channel->capacity_limit
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get channel recommendations for user based on issue type
     * 
     * @param User $user
     * @param string $issueType
     * @param string $priority
     * @return Collection
     */
    public function getChannelRecommendations(User $user, string $issueType, string $priority = 'medium'): Collection
    {
        $availableChannels = $this->getAvailableChannels($user);
        $packageLevel = $this->getUserPackageLevel($user);
        
        // Filter and rank channels based on issue type and priority
        $recommendations = $availableChannels->filter(function ($channel) use ($issueType, $priority) {
            return $this->isChannelSuitableForIssue($channel, $issueType, $priority);
        })->sortBy(function ($channel) use ($priority, $packageLevel) {
            return $this->getChannelPriorityScore($channel, $priority, $packageLevel);
        })->values();

        Log::info('Channel recommendations generated', [
            'user_id' => $user->id,
            'issue_type' => $issueType,
            'priority' => $priority,
            'package_level' => $packageLevel,
            'recommended_channels' => $recommendations->pluck('channel_code')->toArray()
        ]);

        return $recommendations;
    }

    /**
     * Track channel usage and update load
     * 
     * @param string $channelCode
     * @param string $action ('increment' or 'decrement')
     * @return bool
     */
    public function updateChannelLoad(string $channelCode, string $action): bool
    {
        $channel = $this->getChannelByCode($channelCode);
        
        if (!$channel) {
            return false;
        }

        $success = $action === 'increment' ? 
            $channel->incrementLoad() : 
            $channel->decrementLoad();

        if ($success) {
            // Invalidate cache
            $this->invalidateChannelCache($channelCode);
            
            Log::info('Channel load updated', [
                'channel_code' => $channelCode,
                'action' => $action,
                'current_load' => $channel->current_load,
                'load_status' => $channel->getLoadStatus()
            ]);
        }

        return $success;
    }

    /**
     * Get comprehensive channel analytics for user
     * 
     * @param User $user
     * @return array
     */
    public function getChannelAnalytics(User $user): array
    {
        $packageLevel = $this->getUserPackageLevel($user);
        $availableChannels = $this->getAvailableChannels($user);
        $premiumChannels = $this->getPremiumChannels($user);
        $emergencyChannels = $this->getEmergencyChannels($user);

        return [
            'user_id' => $user->id,
            'package_level' => $packageLevel,
            'analysis_timestamp' => now()->toISOString(),
            'channel_summary' => [
                'total_available' => $availableChannels->count(),
                'premium_available' => $premiumChannels->count(),
                'emergency_available' => $emergencyChannels->count(),
                'business_hours_only' => $availableChannels->where('is_business_hours_only', true)->count(),
                'always_available' => $availableChannels->where('is_business_hours_only', false)->count()
            ],
            'recommended_channels' => $this->getTopRecommendedChannels($user),
            'channel_load_status' => $this->getChannelLoadSummary($availableChannels),
            'access_restrictions' => $this->getAccessRestrictions($user),
            'upgrade_benefits' => $this->getUpgradeBenefits($packageLevel)
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Get channel by code
     * 
     * @param string $channelCode
     * @return SupportChannel|null
     */
    private function getChannelByCode(string $channelCode): ?SupportChannel
    {
        return SupportChannel::where('channel_code', $channelCode)
            ->active()
            ->first();
    }

    /**
     * Get user's package level
     * 
     * @param User $user
     * @return string
     */
    private function getUserPackageLevel(User $user): string
    {
        // This should integrate with your subscription system
        return $user->package_level ?? SupportChannel::PACKAGE_STARTER;
    }

    /**
     * Validate package access to channel
     * 
     * @param User $user
     * @param SupportChannel $channel
     * @return bool
     */
    private function validatePackageAccess(User $user, SupportChannel $channel): bool
    {
        $userPackage = $this->getUserPackageLevel($user);
        return $channel->isAvailableForPackage($userPackage);
    }

    /**
     * Check if it's business hours
     * 
     * @return bool
     */
    private function isBusinessHours(): bool
    {
        return SupportChannel::isBusinessHours();
    }

    /**
     * Validate channel capacity
     * 
     * @param SupportChannel $channel
     * @return bool
     */
    private function validateChannelCapacity(SupportChannel $channel): bool
    {
        if (!$channel->capacity_limit) {
            return true; // No capacity limit
        }

        return $channel->current_load < $channel->capacity_limit;
    }

    /**
     * Get unavailability reason
     * 
     * @param SupportChannel $channel
     * @return string
     */
    private function getUnavailabilityReason(SupportChannel $channel): string
    {
        if (!$channel->is_active || !$channel->is_available) {
            return 'Channel is currently inactive';
        }

        if ($channel->is_business_hours_only && !$this->isBusinessHours()) {
            return 'Channel is only available during business hours';
        }

        if ($channel->capacity_limit && $channel->current_load >= $channel->capacity_limit) {
            return 'Channel is currently at capacity';
        }

        return 'Channel is temporarily unavailable';
    }

    /**
     * Check if channel is suitable for issue type
     * 
     * @param SupportChannel $channel
     * @param string $issueType
     * @param string $priority
     * @return bool
     */
    private function isChannelSuitableForIssue(SupportChannel $channel, string $issueType, string $priority): bool
    {
        // Business logic for channel suitability
        // This would be expanded based on your specific requirements
        
        if ($priority === 'critical' || $priority === 'high') {
            // High priority issues should prefer real-time channels
            return in_array($channel->channel_type, [
                SupportChannel::TYPE_PHONE,
                SupportChannel::TYPE_CHAT,
                SupportChannel::TYPE_VIDEO_CALL
            ]);
        }

        // For medium/low priority, any channel is suitable
        return true;
    }

    /**
     * Get channel priority score for ranking
     * 
     * @param SupportChannel $channel
     * @param string $priority
     * @param string $packageLevel
     * @return int
     */
    private function getChannelPriorityScore(SupportChannel $channel, string $priority, string $packageLevel): int
    {
        $score = $channel->priority_order ?? 999;
        
        // Adjust score based on priority and package
        if ($priority === 'critical') {
            $score -= 100;
        } elseif ($priority === 'high') {
            $score -= 50;
        }

        // Premium channels get higher priority for enterprise users
        if ($packageLevel === SupportChannel::PACKAGE_ENTERPRISE && 
            $channel->channel_category === SupportChannel::CATEGORY_PREMIUM) {
            $score -= 200;
        }

        // Penalize high-load channels
        if ($channel->getLoadStatus() === SupportChannel::LOAD_HIGH) {
            $score += 50;
        } elseif ($channel->getLoadStatus() === SupportChannel::LOAD_CRITICAL) {
            $score += 100;
        }

        return $score;
    }

    /**
     * Get top recommended channels for user
     * 
     * @param User $user
     * @return array
     */
    private function getTopRecommendedChannels(User $user): array
    {
        $recommendations = $this->getChannelRecommendations($user, 'general', 'medium');
        
        return $recommendations->take(3)->map(function ($channel) {
            return [
                'channel_code' => $channel->channel_code,
                'display_name' => $channel->display_name,
                'channel_type' => $channel->channel_type,
                'wait_time' => $channel->wait_time_human,
                'load_status' => $channel->getLoadStatus()
            ];
        })->toArray();
    }

    /**
     * Get channel load summary
     * 
     * @param Collection $channels
     * @return array
     */
    private function getChannelLoadSummary(Collection $channels): array
    {
        $loadCounts = $channels->countBy(function ($channel) {
            return $channel->getLoadStatus();
        });

        return [
            'low_load' => $loadCounts[SupportChannel::LOAD_LOW] ?? 0,
            'medium_load' => $loadCounts[SupportChannel::LOAD_MEDIUM] ?? 0,
            'high_load' => $loadCounts[SupportChannel::LOAD_HIGH] ?? 0,
            'critical_load' => $loadCounts[SupportChannel::LOAD_CRITICAL] ?? 0
        ];
    }

    /**
     * Get access restrictions for user's package level
     * 
     * @param User $user
     * @return array
     */
    private function getAccessRestrictions(User $user): array
    {
        $packageLevel = $this->getUserPackageLevel($user);
        $allChannels = SupportChannel::active()->get();
        $availableChannels = $this->getAvailableChannels($user, false);
        
        $restrictedChannels = $allChannels->diff($availableChannels);

        return [
            'package_level' => $packageLevel,
            'restricted_channels' => $restrictedChannels->pluck('channel_code')->toArray(),
            'restrictions_count' => $restrictedChannels->count()
        ];
    }

    /**
     * Get upgrade benefits for current package level
     * 
     * @param string $currentPackage
     * @return array
     */
    private function getUpgradeBenefits(string $currentPackage): array
    {
        $benefits = [];

        if ($currentPackage === SupportChannel::PACKAGE_STARTER) {
            $benefits['professional'] = [
                'additional_channels' => ['phone', 'priority_email'],
                'reduced_wait_times' => true,
                'extended_support_hours' => true
            ];
            $benefits['enterprise'] = [
                'additional_channels' => ['phone', 'priority_email', 'video_call', 'dedicated_manager'],
                'premium_support' => true,
                '24_7_support' => true,
                'no_wait_time' => true
            ];
        } elseif ($currentPackage === SupportChannel::PACKAGE_PROFESSIONAL) {
            $benefits['enterprise'] = [
                'additional_channels' => ['video_call', 'dedicated_manager'],
                'premium_support' => true,
                '24_7_support' => true,
                'priority_escalation' => true
            ];
        }

        return $benefits;
    }

    /**
     * Invalidate channel-related cache
     * 
     * @param string $channelCode
     * @return void
     */
    private function invalidateChannelCache(string $channelCode): void
    {
        $pattern = self::CACHE_PREFIX . "*{$channelCode}*";
        // Implementation would depend on your cache driver
        // For Redis: Cache::tags(['channels'])->flush();
        // For File/Array: would need manual pattern matching
    }
}