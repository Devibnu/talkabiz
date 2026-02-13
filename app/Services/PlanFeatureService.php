<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * PlanFeatureService - Feature Access Control per Plan
 * 
 * Service untuk mengontrol akses fitur berdasarkan paket user.
 * Memungkinkan diferensiasi value antara Starter, Growth, Pro, dan Corporate.
 * 
 * FEATURE TIERS:
 * ==============
 * STARTER (Free):
 *   - Basic inbox
 *   - Basic campaign
 *   - Standard messaging window (09:00-17:00)
 *   - No analytics
 *   - Standard support
 * 
 * GROWTH (Rp 199k):
 *   - Full inbox (labels, notes, quick replies)
 *   - Advanced campaign (scheduling, A/B)
 *   - Extended messaging window (07:00-21:00)
 *   - Basic analytics
 *   - Priority support
 * 
 * PRO (Rp 499k):
 *   - Full inbox + team collaboration
 *   - Advanced campaign + automation
 *   - Flexible messaging window (06:00-22:00)
 *   - Full analytics + export
 *   - Priority support + dedicated
 * 
 * CORPORATE (Custom):
 *   - All features unlocked
 *   - 24/7 messaging window
 *   - Custom analytics + API
 *   - Dedicated support
 * 
 * @author Senior SaaS Scaling Engineer
 */
class PlanFeatureService
{
    // ==================== FEATURE CONSTANTS ====================
    
    // Inbox Features
    const FEATURE_INBOX_BASIC = 'inbox_basic';
    const FEATURE_INBOX_LABELS = 'inbox_labels';
    const FEATURE_INBOX_NOTES = 'inbox_notes';
    const FEATURE_INBOX_QUICK_REPLY = 'inbox_quick_reply';
    const FEATURE_INBOX_TEAM = 'inbox_team';
    const FEATURE_INBOX_PRIORITY = 'inbox_priority';
    
    // Campaign Features
    const FEATURE_CAMPAIGN_BASIC = 'campaign_basic';
    const FEATURE_CAMPAIGN_SCHEDULING = 'campaign_scheduling';
    const FEATURE_CAMPAIGN_AB_TEST = 'campaign_ab_test';
    const FEATURE_CAMPAIGN_AUTOMATION = 'campaign_automation';
    
    // Analytics Features
    const FEATURE_ANALYTICS_BASIC = 'analytics_basic';
    const FEATURE_ANALYTICS_ADVANCED = 'analytics_advanced';
    const FEATURE_ANALYTICS_EXPORT = 'analytics_export';
    const FEATURE_ANALYTICS_API = 'analytics_api';
    
    // Support Features
    const FEATURE_SUPPORT_STANDARD = 'support_standard';
    const FEATURE_SUPPORT_PRIORITY = 'support_priority';
    const FEATURE_SUPPORT_DEDICATED = 'support_dedicated';
    
    // ==================== MESSAGING WINDOWS ====================
    
    /**
     * Get messaging window for user's plan
     * Returns [start_hour, end_hour]
     */
    public function getMessagingWindow(User $user): array
    {
        $plan = $user->currentPlan;
        
        if (!$plan) {
            // No plan = most restrictive
            return ['start' => 9, 'end' => 17];
        }
        
        $code = $plan->code ?? '';
        
        return match(true) {
            str_contains($code, 'enterprise') || str_contains($code, 'corporate') => ['start' => 0, 'end' => 24], // 24/7
            str_contains($code, 'pro') => ['start' => 6, 'end' => 22],
            str_contains($code, 'growth') || str_contains($code, 'business') => ['start' => 7, 'end' => 21],
            default => ['start' => 9, 'end' => 17], // Starter
        };
    }
    
    /**
     * Check if current time is within messaging window
     */
    public function isWithinMessagingWindow(User $user): bool
    {
        $window = $this->getMessagingWindow($user);
        $currentHour = (int) now()->format('H');
        
        // Handle 24/7 window
        if ($window['start'] === 0 && $window['end'] === 24) {
            return true;
        }
        
        return $currentHour >= $window['start'] && $currentHour < $window['end'];
    }
    
    /**
     * Get next available send time if outside window
     */
    public function getNextAvailableSendTime(User $user): ?\Carbon\Carbon
    {
        if ($this->isWithinMessagingWindow($user)) {
            return null; // Already available
        }
        
        $window = $this->getMessagingWindow($user);
        $now = now();
        $currentHour = (int) $now->format('H');
        
        if ($currentHour < $window['start']) {
            // Before window starts today
            return $now->copy()->setTime($window['start'], 0, 0);
        } else {
            // After window ends, next day
            return $now->copy()->addDay()->setTime($window['start'], 0, 0);
        }
    }
    
    // ==================== FEATURE CHECKS ====================
    
    /**
     * Check if user has access to a specific feature
     */
    public function hasFeature(User $user, string $feature): bool
    {
        $plan = $user->currentPlan;
        
        if (!$plan) {
            return false;
        }
        
        $planFeatures = $this->getPlanFeatures($plan);
        
        return in_array($feature, $planFeatures);
    }
    
    /**
     * Get all features available for a plan
     */
    public function getPlanFeatures(Plan $plan): array
    {
        $code = $plan->code ?? '';
        
        // Corporate/Enterprise - ALL features
        if (str_contains($code, 'enterprise') || str_contains($code, 'corp')) {
            return [
                // Inbox
                self::FEATURE_INBOX_BASIC,
                self::FEATURE_INBOX_LABELS,
                self::FEATURE_INBOX_NOTES,
                self::FEATURE_INBOX_QUICK_REPLY,
                self::FEATURE_INBOX_TEAM,
                self::FEATURE_INBOX_PRIORITY,
                // Campaign
                self::FEATURE_CAMPAIGN_BASIC,
                self::FEATURE_CAMPAIGN_SCHEDULING,
                self::FEATURE_CAMPAIGN_AB_TEST,
                self::FEATURE_CAMPAIGN_AUTOMATION,
                // Analytics
                self::FEATURE_ANALYTICS_BASIC,
                self::FEATURE_ANALYTICS_ADVANCED,
                self::FEATURE_ANALYTICS_EXPORT,
                self::FEATURE_ANALYTICS_API,
                // Support
                self::FEATURE_SUPPORT_PRIORITY,
                self::FEATURE_SUPPORT_DEDICATED,
            ];
        }
        
        // Pro
        if (str_contains($code, 'pro')) {
            return [
                // Inbox
                self::FEATURE_INBOX_BASIC,
                self::FEATURE_INBOX_LABELS,
                self::FEATURE_INBOX_NOTES,
                self::FEATURE_INBOX_QUICK_REPLY,
                self::FEATURE_INBOX_TEAM,
                self::FEATURE_INBOX_PRIORITY,
                // Campaign
                self::FEATURE_CAMPAIGN_BASIC,
                self::FEATURE_CAMPAIGN_SCHEDULING,
                self::FEATURE_CAMPAIGN_AB_TEST,
                self::FEATURE_CAMPAIGN_AUTOMATION,
                // Analytics
                self::FEATURE_ANALYTICS_BASIC,
                self::FEATURE_ANALYTICS_ADVANCED,
                self::FEATURE_ANALYTICS_EXPORT,
                // Support
                self::FEATURE_SUPPORT_PRIORITY,
                self::FEATURE_SUPPORT_DEDICATED,
            ];
        }
        
        // Growth/Business
        if (str_contains($code, 'growth') || str_contains($code, 'business')) {
            return [
                // Inbox
                self::FEATURE_INBOX_BASIC,
                self::FEATURE_INBOX_LABELS,
                self::FEATURE_INBOX_NOTES,
                self::FEATURE_INBOX_QUICK_REPLY,
                self::FEATURE_INBOX_PRIORITY,
                // Campaign
                self::FEATURE_CAMPAIGN_BASIC,
                self::FEATURE_CAMPAIGN_SCHEDULING,
                self::FEATURE_CAMPAIGN_AB_TEST,
                // Analytics
                self::FEATURE_ANALYTICS_BASIC,
                // Support
                self::FEATURE_SUPPORT_PRIORITY,
            ];
        }
        
        // Starter (default) - Basic features only
        return [
            self::FEATURE_INBOX_BASIC,
            self::FEATURE_CAMPAIGN_BASIC,
            self::FEATURE_SUPPORT_STANDARD,
        ];
    }
    
    // ==================== ANALYTICS ACCESS ====================
    
    /**
     * Check if user can access analytics
     */
    public function canAccessAnalytics(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_ANALYTICS_BASIC);
    }
    
    /**
     * Check if user can access advanced analytics
     */
    public function canAccessAdvancedAnalytics(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_ANALYTICS_ADVANCED);
    }
    
    /**
     * Check if user can export analytics
     */
    public function canExportAnalytics(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_ANALYTICS_EXPORT);
    }
    
    // ==================== INBOX ACCESS ====================
    
    /**
     * Check if user has inbox priority (faster processing)
     */
    public function hasInboxPriority(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_INBOX_PRIORITY);
    }
    
    /**
     * Check if user can use inbox labels
     */
    public function canUseInboxLabels(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_INBOX_LABELS);
    }
    
    /**
     * Check if user can use quick replies
     */
    public function canUseQuickReplies(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_INBOX_QUICK_REPLY);
    }
    
    // ==================== CAMPAIGN ACCESS ====================
    
    /**
     * Check if user can schedule campaigns
     */
    public function canScheduleCampaigns(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_CAMPAIGN_SCHEDULING);
    }
    
    /**
     * Check if user can use A/B testing
     */
    public function canUseABTesting(User $user): bool
    {
        return $this->hasFeature($user, self::FEATURE_CAMPAIGN_AB_TEST);
    }
    
    // ==================== PLAN TIER HELPERS ====================
    
    /**
     * Get plan tier level (for comparison)
     * Higher = better plan
     */
    public function getPlanTier(User $user): int
    {
        $plan = $user->currentPlan;
        
        if (!$plan) {
            return 0;
        }
        
        $code = $plan->code ?? '';
        
        return match(true) {
            str_contains($code, 'enterprise') => 5,
            str_contains($code, 'corp') => 4,
            str_contains($code, 'pro') => 3,
            str_contains($code, 'growth') || str_contains($code, 'business') => 2,
            default => 1, // Starter
        };
    }
    
    /**
     * Check if user is on paid plan
     */
    public function isOnPaidPlan(User $user): bool
    {
        return $this->getPlanTier($user) >= 2;
    }
    
    /**
     * Get feature comparison for upgrade page
     */
    public function getFeatureComparison(): array
    {
        return [
            [
                'name' => 'Messaging Window',
                'starter' => '09:00 - 17:00',
                'growth' => '07:00 - 21:00',
                'pro' => '06:00 - 22:00',
                'corporate' => '24/7',
            ],
            [
                'name' => 'Inbox Labels & Notes',
                'starter' => false,
                'growth' => true,
                'pro' => true,
                'corporate' => true,
            ],
            [
                'name' => 'Quick Replies',
                'starter' => false,
                'growth' => true,
                'pro' => true,
                'corporate' => true,
            ],
            [
                'name' => 'Campaign Scheduling',
                'starter' => false,
                'growth' => true,
                'pro' => true,
                'corporate' => true,
            ],
            [
                'name' => 'A/B Testing',
                'starter' => false,
                'growth' => true,
                'pro' => true,
                'corporate' => true,
            ],
            [
                'name' => 'Analytics',
                'starter' => false,
                'growth' => 'Basic',
                'pro' => 'Advanced + Export',
                'corporate' => 'Full + API',
            ],
            [
                'name' => 'Inbox Priority',
                'starter' => false,
                'growth' => true,
                'pro' => true,
                'corporate' => true,
            ],
            [
                'name' => 'Support',
                'starter' => 'Standard',
                'growth' => 'Priority',
                'pro' => 'Dedicated',
                'corporate' => 'Dedicated + SLA',
            ],
        ];
    }
}
